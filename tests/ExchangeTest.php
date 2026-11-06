<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Register;
use App\Models\Sale;
use App\Services\CashService;
use App\Services\ExchangeService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-11-06 (Aya): an exchange at the till — the customer brings items back and takes others instead, in one step.
 * One atomic record of a sale (cash in = the return's value + what he pays) and a return (its value went out as the
 * new goods), so the drawer nets to the difference alone and every rule keeps working: stock both ways, refunds at
 * paid-or-today's-lower, and a leftover follows the return rules (a credit customer's debt first, then cash back).
 */
$setup = static function (): array {
    $svc = new ProductService();
    $mint = make_product('Nakhla Mint');
    $mintP = $svc->addUnit($mint, 'Piece', '1', false, true);
    $svc->setPrices($mintP, '3.00', '');
    (new StockService())->adjust($mint, $mintP, '50', 'opening', '', '1', TEST_ADMIN_ID);
    $grape = make_product('Nakhla Grape');
    $grapeP = $svc->addUnit($grape, 'Piece', '1', false, true);
    $svc->setPrices($grapeP, '3.00', '');
    (new StockService())->adjust($grape, $grapeP, '50', 'opening', '', '1', TEST_ADMIN_ID);
    $fakher = make_product('Al Fakher');
    $fakherP = $svc->addUnit($fakher, 'Piece', '1', false, true);
    $svc->setPrices($fakherP, '5.00', '');
    (new StockService())->adjust($fakher, $fakherP, '50', 'opening', '', '1', TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '1000000');
    $sale = (new SaleService())->complete([
        'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
        'lines' => [['product_id' => $mint, 'unit_id' => $mintP, 'qty' => '2']], 'invoice_discount' => '',
        'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '6']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
    ]);

    return ['mint' => $mint, 'mintP' => $mintP, 'grape' => $grape, 'grapeP' => $grapeP, 'fakher' => $fakher, 'fakherP' => $fakherP,
            'register' => $register, 'session' => $session, 'sale' => (int) $sale['id'], 'item' => (int) (new Sale())->items((int) $sale['id'])[0]['id']];
};
$stockOf = static fn (int $productId): int => (int) Database::pdo()->query("SELECT stock_base FROM products WHERE id = {$productId}")->fetchColumn();

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'an even swap: mint back, grape taken, nothing to pay, the drawer does not move' => function () use ($setup, $stockOf): void {
        $s = $setup();
        $before = (new CashSession())->expected($s['session']);
        $r = (new ExchangeService())->create($s['sale'], [['sale_item_id' => $s['item'], 'qty' => '1', 'condition' => 'restock']], null,
            [['product_id' => $s['grape'], 'unit_id' => $s['grapeP'], 'qty' => '1']], 'retail', '', '', 'LBP', '', $s['session'], $s['register'], TEST_ADMIN_ID, false, '');
        assert_same(['3.00', '3.00'], [$r['return_value'], $r['new_total']]);
        assert_same($before, (new CashSession())->expected($s['session']), 'the drawer nets to zero');
        assert_same(49, $stockOf($s['mint']), '50 sold down to 48, then 1 back');
        assert_same(49, $stockOf($s['grape']), '50 - 1 taken');
        // both halves written and linked
        assert_same('completed', Database::pdo()->query("SELECT status FROM sales WHERE id = {$r['sale']['id']}")->fetchColumn());
        assert_contains('Exchange with ' . $r['sale']['invoice_no'], (string) Database::pdo()->query("SELECT reason FROM returns WHERE id = {$r['return']['id']}")->fetchColumn());
        assert_same('3.00', $r['return']['exchanged_usd']);
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'exchange.recorded'")->fetchColumn());
    },

    'dearer item taken: he pays only the difference; the drawer gains exactly that' => function () use ($setup): void {
        $s = $setup();
        $before = (new CashSession())->expected($s['session']);
        $r = (new ExchangeService())->create($s['sale'], [['sale_item_id' => $s['item'], 'qty' => '1', 'condition' => 'restock']], null,
            [['product_id' => $s['fakher'], 'unit_id' => $s['fakherP'], 'qty' => '1']], 'retail', '2.00', '', 'LBP', '', $s['session'], $s['register'], TEST_ADMIN_ID, false, '');
        assert_same(['3.00', '5.00'], [$r['return_value'], $r['new_total']]);
        $after = (new CashSession())->expected($s['session']);
        assert_same('2.00', number_format((float) $after['USD'] - (float) $before['USD'], 2, '.', ''), 'the drawer gained the $2 difference');
        assert_same($before['LBP'], $after['LBP']);
        // short payment is refused with the sale's own words
        $e = assert_throws(DomainException::class, fn () => (new ExchangeService())->create($s['sale'], [['sale_item_id' => $s['item'], 'qty' => '1', 'condition' => 'restock']], null,
            [['product_id' => $s['fakher'], 'unit_id' => $s['fakherP'], 'qty' => '1']], 'retail', '1.00', '', 'LBP', '', $s['session'], $s['register'], TEST_ADMIN_ID, false, ''));
        assert_contains('Not fully paid', $e->getMessage());
    },

    'cheaper item taken by a credit customer: the leftover reduces the debt first, the rest is given back' => function () use ($setup): void {
        $s = $setup();
        $c = (new Customer())->create(['name' => 'Lounge 961', 'phone' => null, 'notes' => null, 'default_price_level' => 'retail', 'credit_limit_usd' => null]);
        (new Customer())->addLedger(['customer_id' => $c, 'type' => 'sale_credit', 'amount_usd' => '1.00', 'note' => 'old debt']);
        Database::pdo()->exec("UPDATE sales SET customer_id = {$c} WHERE id = {$s['sale']}");
        $before = (new CashSession())->expected($s['session']);
        // returns 2 mint ($6), takes 1 fakher ($5): $1 leftover -> $1 off the debt, nothing in cash
        $r = (new ExchangeService())->create($s['sale'], [['sale_item_id' => $s['item'], 'qty' => '2', 'condition' => 'restock']], null,
            [['product_id' => $s['fakher'], 'unit_id' => $s['fakherP'], 'qty' => '1']], 'retail', '', '', 'LBP', '', $s['session'], $s['register'], TEST_ADMIN_ID, false, '');
        assert_same(['6.00', '5.00'], [$r['return_value'], $r['new_total']]);
        assert_same('1.00', $r['return']['debt_reduction']);
        assert_same('0.00', (new Customer())->balance($c));
        assert_same($before, (new CashSession())->expected($s['session']), 'goods for goods and debt: no money moved');
    },

    'atomic: when the return half fails, the sale half is rolled back too' => function () use ($setup): void {
        $s = $setup();
        $sales = (int) Database::pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn();
        $e = assert_throws(DomainException::class, fn () => (new ExchangeService())->create($s['sale'], [['sale_item_id' => $s['item'], 'qty' => '5', 'condition' => 'restock']], null,
            [['product_id' => $s['grape'], 'unit_id' => $s['grapeP'], 'qty' => '1']], 'retail', '', '', 'LBP', '', $s['session'], $s['register'], TEST_ADMIN_ID, false, ''));
        assert_contains('can still be returned', $e->getMessage());
        assert_same($sales, (int) Database::pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn(), 'no sale left behind');
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM returns')->fetchColumn());
    },

    'through the browser: the pop-up offers the exchange and the endpoint records it' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $till = $client->get('pos')->body;
        assert_contains('He takes something else instead', $till);
        assert_contains('id="exch-box"', $till);
        $r = $client->postJson('pos/exchange', ['sale_id' => $s['sale'], 'items' => [['sale_item_id' => $s['item'], 'qty' => '1', 'condition' => 'restock']],
            'new_lines' => [['product_id' => $s['grape'], 'unit_id' => $s['grapeP'], 'qty' => '1']],
            'usd' => '', 'lbp' => '', 'change_currency' => 'LBP', 'price_level' => 'retail', 'reason' => 'wrong flavour', 'pin' => '']);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same(['3.00', '3.00', '3.00'], [$j['return_value'], $j['new_total'], $j['exchanged_usd']]);
        assert_contains('RTN-', $j['return_no']);
        assert_contains('INV-', $j['invoice_no']);
    },
];
