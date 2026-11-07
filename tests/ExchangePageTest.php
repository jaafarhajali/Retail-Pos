<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Register;
use App\Models\Sale;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-11-07 (Aya): exchanges outside the till are their own tab beside Returns, like on the till.
 * The page finds the invoice, takes what comes back and what he takes instead, and records the same
 * atomic pair through ExchangeService — so the office and the till can never drift apart.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $mint = make_product('Nakhla Mint');
    $mintP = $svc->addUnit($mint, 'Piece', '1', false, true);
    $svc->setPrices($mintP, '3.00', '');
    (new StockService())->adjust($mint, $mintP, '50', 'opening', '', '1', TEST_ADMIN_ID);
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

    return ['fakher' => $fakher, 'fakherP' => $fakherP, 'register' => $register, 'session' => $session,
            'sale' => (int) $sale['id'], 'invoice' => (string) $sale['invoice_no'],
            'item' => (int) (new Sale())->items((int) $sale['id'])[0]['id']];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the sidebar and the page: Exchanges stands beside Returns and finds the invoice' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('>Exchanges<', $client->get('dashboard')->body, 'the sidebar link');
        $page = $client->get('exchanges')->body;
        assert_contains('Find invoice', $page);
        assert_contains('No exchanges yet', $page);
        $page = $client->get('exchanges', ['invoice' => $s['invoice']])->body;
        assert_contains('He brings back', $page);
        assert_contains('He takes instead', $page);
        assert_contains('Nakhla Mint', $page);
        assert_contains('Record exchange', $page);
    },

    'recorded from the office: a dearer item, the drawer gains exactly the difference' => function () use ($setup): void {
        $s = $setup();
        $before = (new CashSession())->expected($s['session']);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $r = $client->post('exchanges/store', [
            'sale_id' => $s['sale'], 'items' => [$s['item'] => ['qty' => '1', 'condition' => 'restock']],
            'new' => [['product_id' => $s['fakher'], 'unit_id' => $s['fakherP'], 'qty' => '1']],
            'usd_part' => '2.00', 'lbp_part' => '', 'reason' => 'office swap',
        ]);
        assert_same(302, $r->status, $r->body);
        $after = (new CashSession())->expected($s['session']);
        assert_same('2.00', number_format((float) $after['USD'] - (float) $before['USD'], 2, '.', ''), 'the drawer gained the $2 difference');
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM returns WHERE reason LIKE 'Exchange with INV-%'")->fetchColumn());
        // and the page now lists it
        assert_contains('office swap', (string) Database::pdo()->query('SELECT reason FROM returns ORDER BY id DESC LIMIT 1')->fetchColumn());
        $list = $client->get('exchanges')->body;
        assert_contains('RTN-', $list);
        // Open shows the whole exchange, not the bare return record (Aya, 2026-11-07)
        assert_contains('exchanges/view', $list);
        $retId = (int) Database::pdo()->query('SELECT id FROM returns ORDER BY id DESC LIMIT 1')->fetchColumn();
        $page = $client->get('exchanges/view', ['id' => $retId])->body;
        assert_contains('He brought back', $page);
        assert_contains('He took instead', $page);
        assert_contains('Nakhla Mint', $page);
        assert_contains('Al Fakher', $page);
        assert_contains('the customer paid $2.00', $page);
        assert_contains('office swap', $page, 'the reason without the technical prefix');
    },

    'refused without a line taken: nothing is written, the form comes back' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $r = $client->post('exchanges/store', [
            'sale_id' => $s['sale'], 'items' => [$s['item'] => ['qty' => '1', 'condition' => 'restock']],
            'new' => [], 'usd_part' => '', 'lbp_part' => '', 'reason' => '',
        ]);
        assert_same(302, $r->status, $r->body);
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM returns')->fetchColumn());
        assert_contains('Add at least one item he takes instead.', $client->get('exchanges', ['invoice' => $s['invoice']])->body);
    },
];
