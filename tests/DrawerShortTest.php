<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Register;
use App\Models\Sale;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * I5 (option A, 2026-10-03): change or a cash refund that needs more of a currency than the drawer holds is stopped with a
 * warning; the cashier pays in the other currency, records a Cash in, or confirms she adds the money herself (audited).
 * S-000004: 10,000 LBP in the drawer, a 650,000 LBP refund, and Expected went to −640,000 LBP.
 */
$setup = static function (): array {
    $p = make_product('Adalya Love 66');
    $pack = (new ProductService())->addUnit($p, 'Pack', '1', false, true);
    (new ProductService())->setPrices($pack, '7.24', '');
    (new StockService())->adjust($p, $pack, '10', 'opening', '', '5', TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '30', '10000');

    return ['p' => $p, 'pack' => $pack, 'register' => $register, 'session' => $session];
};
$sell = static fn (array $s, array $payments, array $extra = []): array => (new SaleService())->complete($extra + [
    'register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
    'lines' => [['product_id' => $s['p'], 'unit_id' => $s['pack'], 'qty' => '1']], 'invoice_discount' => '', 'payments' => $payments,
    'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
]);
$giveBack = static function (array $s, int $saleId, string $currency, bool $allow = false): array {
    $item = (new Sale())->items($saleId)[0];

    return (new ReturnService())->create($saleId, [['sale_item_id' => (int) $item['id'], 'qty' => '1', 'condition' => 'restock']], $currency, '', $s['session'], $s['register'], TEST_ADMIN_ID, $allow);
};
$count = static fn (string $sql): int => (int) Database::pdo()->query($sql)->fetchColumn();

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a refund of 650,000 LBP from a drawer with 10,000 LBP is stopped with a warning, and nothing is recorded' => function () use ($setup, $sell, $giveBack, $count): void {
        $s = $setup();
        $sale = $sell($s, [['method' => 'cash', 'currency' => 'USD', 'amount' => '7.24']]);
        $e = assert_throws(DomainException::class, fn () => $giveBack($s, (int) $sale['id'], 'LBP'));
        assert_same(CashService::SHORT_DRAWER, $e->getCode());
        assert_contains('The drawer has only 10,000 LBP', $e->getMessage());
        assert_contains('650,000 LBP', $e->getMessage());
        assert_same(0, $count('SELECT COUNT(*) FROM returns'));
        assert_same('10000', (new \App\Models\CashSession())->expected($s['session'])['LBP']);
    },

    'the same refund in USD goes through: the drawer has the dollars' => function () use ($setup, $sell, $giveBack): void {
        $s = $setup();
        $sale = $sell($s, [['method' => 'cash', 'currency' => 'USD', 'amount' => '7.24']]);
        $ret = $giveBack($s, (int) $sale['id'], 'USD');
        assert_same(['currency' => 'USD', 'amount' => '7.24'], $ret['cash']);
    },

    'confirmed, the refund is recorded and the audit log says the drawer was short' => function () use ($setup, $sell, $giveBack, $count): void {
        $s = $setup();
        $sale = $sell($s, [['method' => 'cash', 'currency' => 'USD', 'amount' => '7.24']]);
        $giveBack($s, (int) $sale['id'], 'LBP', true);
        assert_same(1, $count('SELECT COUNT(*) FROM returns'));
        assert_same(1, $count("SELECT COUNT(*) FROM audit_log WHERE action = 'drawer.short' AND entity = 'return'"));
    },

    'change of 250,000 LBP from a drawer with 10,000 LBP is stopped; confirmed, the sale goes through and is audited' => function () use ($setup, $sell, $count): void {
        $s = $setup();
        // $7.24 paid with $10: $2.76 back = 248,400 → 250,000 LBP of change, the drawer has 10,000
        $e = assert_throws(DomainException::class, fn () => $sell($s, [['method' => 'cash', 'currency' => 'USD', 'amount' => '10']]));
        assert_same(CashService::SHORT_DRAWER, $e->getCode());
        assert_contains('250,000 LBP of change', $e->getMessage());
        assert_same(0, $count('SELECT COUNT(*) FROM sales'));
        $r = $sell($s, [['method' => 'cash', 'currency' => 'USD', 'amount' => '10']], ['allow_short_drawer' => true]);
        assert_same(250000, $r['change_lbp']);
        assert_same(1, $count("SELECT COUNT(*) FROM audit_log WHERE action = 'drawer.short' AND entity = 'sale'"));
    },

    'lira paid in the same sale counts: change taken from what the customer just handed over is fine' => function () use ($setup, $sell): void {
        $s = $setup();
        // paid 700,000 LBP for $7.24 (651,600): 48,400 → 50,000 LBP of change; the drawer has 10,000 + 700,000
        $r = $sell($s, [['method' => 'cash', 'currency' => 'LBP', 'amount' => '700000']]);
        assert_same(50000, $r['change_lbp']);
    },

    'the returns page shows the warning, keeps what was typed and records the refund once the box is ticked' => function () use ($setup, $sell, $count): void {
        $s = $setup();
        $sale = $sell($s, [['method' => 'cash', 'currency' => 'USD', 'amount' => '7.24']]);
        $itemId = (int) (new Sale())->items((int) $sale['id'])[0]['id'];
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $client->get('returns', ['invoice' => '1']);
        $form = ['sale_id' => $sale['id'], "items[{$itemId}][qty]" => '1', "items[{$itemId}][condition]" => 'waste', 'cash_currency' => 'LBP', 'reason' => 'wrong flavour'];
        assert_same(302, $client->post('returns/store', $form)->status);
        $page = $client->get('returns', ['invoice' => 'INV-000001'])->body;
        assert_contains('The drawer has only 10,000 LBP', $page);
        assert_contains('name="allow_short_drawer"', $page);
        assert_contains('value="1" inputmode="decimal"', $page, 'the quantity typed is kept');
        assert_contains('value="wrong flavour"', $page);
        assert_same(0, $count('SELECT COUNT(*) FROM returns'));
        assert_same(302, $client->post('returns/store', $form + ['allow_short_drawer' => '1'])->status);
        assert_same(1, $count('SELECT COUNT(*) FROM returns'));
    },

    'the till gets short_drawer so it can offer to continue' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $r = $client->postJson('pos/complete', ['price_level' => 'retail', 'lines' => [['product_id' => $s['p'], 'unit_id' => $s['pack'], 'qty' => '1']],
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '10']], 'change_currency' => 'LBP']);
        assert_same(422, $r->status);
        $j = json_decode($r->body, true);
        assert_true($j['short_drawer'] === true, $r->body);
        $ok = $client->postJson('pos/complete', ['price_level' => 'retail', 'lines' => [['product_id' => $s['p'], 'unit_id' => $s['pack'], 'qty' => '1']],
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '10']], 'change_currency' => 'LBP', 'allow_short_drawer' => true]);
        assert_same(200, $ok->status, $ok->body);
    },
];
