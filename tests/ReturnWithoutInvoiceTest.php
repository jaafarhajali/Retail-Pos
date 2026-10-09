<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Register;
use App\Models\SaleReturn;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\ReturnService;
use App\Services\StockService;

/**
 * Owner, 2026-10-09: a customer brings items back with an invoice the shop cannot find among the day's sales. The
 * return is recorded without the invoice: items chosen by hand, refunded at today's retail price of the unit or lower.
 */
/** Al Fakher Mint, sold as "Pack 250g" at $9 (cost $6), 10 packs in stock. */
$products = static function (): array {
    $svc = new ProductService();
    $tobacco = make_product('Al Fakher Mint', 'g');
    $pack = $svc->addUnit($tobacco, 'Pack', '250', false, true);
    $svc->setPrices($pack, '9', '8');
    $svc->setCost($tobacco, '6', 250);
    (new StockService())->adjust($tobacco, $pack, '10', 'opening', '', null, TEST_ADMIN_ID);

    return ['tobacco' => $tobacco, 'pack' => $pack];
};
$setup = static function () use ($products): array {
    $registerId = (new Register())->create('Register 01');
    $sessionId = (new CashService())->open($registerId, TEST_ADMIN_ID, '100', '0');

    return $products() + ['register' => $registerId, 'session' => $sessionId];
};
$stock = static fn (int $productId): int => (int) (new Product())->find($productId)['stock_base'];

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'two packs come back without an invoice: refunded at today\'s price, back in stock, out of the drawer' => function () use ($setup, $stock): void {
        $s = $setup();
        $r = (new ReturnService())->createWithoutSale([['product_id' => $s['tobacco'], 'unit_id' => $s['pack'], 'qty' => '2', 'price' => '', 'condition' => 'restock']],
            null, 'wrong flavour', $s['session'], $s['register'], TEST_ADMIN_ID, false, '18', '');
        assert_same('18.00', $r['total_usd']);
        assert_same([['currency' => 'USD', 'amount' => '18.00']], $r['cash_parts']);
        assert_same(3000, $stock($s['tobacco']), '10 packs + 2');
        assert_same(['USD' => '82.00', 'LBP' => '0'], (new CashSession())->expected($s['session']));
        $row = Database::pdo()->query('SELECT sale_id, customer_id, total_usd FROM returns')->fetch(PDO::FETCH_ASSOC);
        assert_same(['sale_id' => null, 'customer_id' => null, 'total_usd' => '18.00'], $row);
        $item = Database::pdo()->query('SELECT sale_item_id, product_id, product_unit_id, product_name, unit_name, unit_price_usd, qty, base_qty, refund_usd, cost_usd FROM return_items')->fetch(PDO::FETCH_ASSOC);
        assert_same(['sale_item_id' => null, 'product_id' => $s['tobacco'], 'product_unit_id' => $s['pack'], 'product_name' => 'Al Fakher Mint', 'unit_name' => 'Pack 250g',
            'unit_price_usd' => '9.00', 'qty' => '2.000', 'base_qty' => 500, 'refund_usd' => '18.00', 'cost_usd' => '12.00'], array_map(static fn ($v) => is_numeric($v) && !str_contains((string) $v, '.') ? (int) $v : $v, $item));
        assert_same('Al Fakher Mint', (new SaleReturn())->items((int) $r['id'])[0]['product_name'], 'the name comes from the return line itself');
        assert_contains('without_invoice', (string) Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'return.created'")->fetchColumn());
    },

    'the price can be lowered but never raised above today\'s retail price; damaged items do not go back to stock' => function () use ($setup, $stock): void {
        $s = $setup();
        $svc = new ReturnService();
        $e = assert_throws(DomainException::class, fn () => $svc->createWithoutSale([['product_id' => $s['tobacco'], 'unit_id' => $s['pack'], 'qty' => '1', 'price' => '9.50', 'condition' => 'restock']],
            null, '', $s['session'], $s['register'], TEST_ADMIN_ID, false, '9.50', ''));
        assert_contains("refunded at today's price of $9.00 per Pack 250g at most", $e->getMessage());
        $r = $svc->createWithoutSale([['product_id' => $s['tobacco'], 'unit_id' => $s['pack'], 'qty' => '1', 'price' => '8', 'condition' => 'waste']],
            null, '', $s['session'], $s['register'], TEST_ADMIN_ID, false, '8', '');
        assert_same('8.00', $r['total_usd']);
        assert_same(2500, $stock($s['tobacco']), 'restocked then written off: the stock is unchanged');
        assert_same(2, (int) Database::pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE ref_type = 'return'")->fetchColumn());
        $e = assert_throws(DomainException::class, fn () => $svc->createWithoutSale([], null, '', $s['session'], $s['register'], TEST_ADMIN_ID));
        assert_contains('at least one item', $e->getMessage());
    },

    'the till records it with the PIN; the receipt and the lists say "no invoice"' => function () use ($products): void {
        $s = $products();
        $register = (new Register())->create('Register 01');
        (new \App\Models\User())->setPin(TEST_ADMIN_ID, '2468');
        make_user('sara');
        $admin = login_as('admin', TEST_ADMIN_PASSWORD);
        $admin->get('registers');
        $admin->post('registers/bind', ['id' => $register]);
        $client = new HttpClient();
        $client->setCookie(\App\Core\RegisterDevice::COOKIE, (string) $admin->cookie(\App\Core\RegisterDevice::COOKIE));
        $client->get('auth/login');
        assert_same(302, $client->post('auth/login', ['username' => 'sara', 'password' => 'password123'])->status);
        $client->get('sessions/open');
        assert_same(302, $client->post('sessions/open', ['opening_usd' => '50', 'opening_lbp' => '0'])->status);
        $client->get('pos');
        $items = [['product_id' => $s['tobacco'], 'unit_id' => $s['pack'], 'qty' => '1', 'price' => '9.00', 'condition' => 'restock']];
        $r = $client->postJson('pos/return', ['sale_id' => 0, 'customer_id' => null, 'items' => $items, 'usd' => '9', 'lbp' => '', 'reason' => '', 'pin' => '']);
        assert_same(422, $r->status, 'a cashier needs the PIN');
        assert_true(json_decode($r->body, true)['needs_pin'] === true);
        $r = $client->postJson('pos/return', ['sale_id' => 0, 'customer_id' => null, 'items' => $items, 'usd' => '9', 'lbp' => '', 'reason' => 'no invoice found', 'pin' => '2468']);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same('9.00', $j['total_usd']);
        assert_contains('No invoice', $client->get('returns/receipt', ['id' => $j['id']])->body);
        assert_contains('no invoice', $admin->get('returns')->body);
        assert_contains('returned by item', $admin->get('returns/view', ['id' => $j['id']])->body);
        assert_contains('no invoice', $admin->get('reports', ['type' => 'returns'])->body);
    },
];
