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
use App\Services\ProductService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-11-05 (Aya): a refund can be given back in cash or sent by Whish, on the Returns page and in the till's
 * pop-up. By Whish: USD only, exact to the cent (no 5,000 rounding, so no rounding kept), from the shop's Whish
 * account and never from the drawer. A credit customer's debt is still reduced first; only the rest is sent.
 */
$setup = static function (string $price): array {
    $p = make_product('Fumari White Gummi Bear');
    $pack = (new ProductService())->addUnit($p, 'Pack', '1', false, true);
    (new ProductService())->setPrices($pack, $price, '');
    (new StockService())->adjust($p, $pack, '10', 'opening', '', '5', TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '1000000');
    $sale = (new SaleService())->complete([
        'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
        'lines' => [['product_id' => $p, 'unit_id' => $pack, 'qty' => '1']], 'invoice_discount' => '',
        'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => $price]], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
    ]);

    return ['register' => $register, 'session' => $session, 'sale' => (int) $sale['id']];
};
$whishBack = static function (array $s): array {
    $item = (new Sale())->items($s['sale'])[0];

    return (new ReturnService())->create($s['sale'], [['sale_item_id' => (int) $item['id'], 'qty' => '1', 'condition' => 'restock']],
        'MIX', '', $s['session'], $s['register'], TEST_ADMIN_ID, false, null, null, 'whish');
};
$refunds = static fn (int $returnId): array => Database::pdo()->query("SELECT method, currency, amount, amount_usd FROM return_refunds WHERE return_id = {$returnId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'sent by Whish: exact cents, nothing leaves the drawer, no rounding kept' => function () use ($setup, $whishBack, $refunds): void {
        $s = $setup('7.24');
        $before = (new CashSession())->expected($s['session']);
        $ret = $whishBack($s);
        assert_same([['method' => 'whish', 'currency' => 'USD', 'amount' => '7.24', 'amount_usd' => '7.24']], $refunds((int) $ret['id']));
        assert_same('0.00', $ret['rounding_usd'], 'a transfer is exact: no 5,000 rounding');
        assert_same($before, (new CashSession())->expected($s['session']), 'the drawer did not move');
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM cash_movements WHERE type = 'refund'")->fetchColumn());
    },

    'a credit customer: the debt is reduced first, only the rest goes by Whish' => function () use ($setup, $refunds): void {
        $s = $setup('9.50');
        $customer = (new Customer())->create(['name' => 'Lounge 961', 'phone' => null, 'notes' => null, 'default_price_level' => 'retail', 'credit_limit_usd' => null]);
        (new Customer())->addLedger(['customer_id' => $customer, 'type' => 'sale_credit', 'amount_usd' => '4.00', 'note' => 'old debt']);
        Database::pdo()->exec("UPDATE sales SET customer_id = {$customer} WHERE id = {$s['sale']}");
        $item = (new Sale())->items($s['sale'])[0];
        $ret = (new ReturnService())->create($s['sale'], [['sale_item_id' => (int) $item['id'], 'qty' => '1', 'condition' => 'restock']],
            'MIX', '', $s['session'], $s['register'], TEST_ADMIN_ID, false, null, null, 'whish');
        assert_same([
            ['method' => 'debt_reduction', 'currency' => 'USD', 'amount' => '4.00', 'amount_usd' => '4.00'],
            ['method' => 'whish', 'currency' => 'USD', 'amount' => '5.50', 'amount_usd' => '5.50'],
        ], $refunds((int) $ret['id']));
        assert_same('0.00', (new Customer())->balance($customer));
    },

    'the Returns page and the till pop-up offer Cash / Whish, and the pages name the Whish refund' => function () use ($setup): void {
        $s = $setup('9.50');
        $itemId = (int) (new Sale())->items($s['sale'])[0]['id'];
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $page = $client->get('returns', ['invoice' => 'INV-000001'])->body;
        assert_contains('name="refund_method"', $page);
        assert_contains('>Whish</', $page);
        assert_contains('id="ret-method"', $client->get('pos')->body, 'the till pop-up too');
        $r = $client->post('returns/store', ['sale_id' => (string) $s['sale'], "items[{$itemId}][qty]" => '1', "items[{$itemId}][condition]" => 'restock',
            'cash_currency' => 'MIX', 'refund_method' => 'whish', 'usd_part' => '', 'lbp_part' => '', 'reason' => '']);
        assert_same(302, $r->status);
        $view = $client->get('returns/view', ['id' => 1])->body;
        assert_contains('Whish', $view, 'How it was refunded says Whish');
        assert_contains('$9.50', $view);
    },
];
