<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Register;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-11-07 (Aya, from the owner): "average bl chaher w bl nhar adde mabi3 hayda l item" — per item,
 * how much sells per day and per month, on the dashboard. Per day: the last 30 days net of returns,
 * a younger product divides by its own age. Per month: the average of the last 3 full calendar months,
 * null until a product has one full month behind it.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $p = make_product('Nakhla Mint');
    $u = $svc->addUnit($p, 'Piece', '1', false, true);
    $svc->setPrices($u, '3.00', '');
    (new StockService())->adjust($p, $u, '500', 'opening', '', '1', TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '1000000');
    $sell = static function (string $qty) use ($p, $u, $register, $session): int {
        $sale = (new SaleService())->complete([
            'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $p, 'unit_id' => $u, 'qty' => $qty]], 'invoice_discount' => '',
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '2000']], 'change_currency' => 'USD', 'notes' => '', 'pin' => '',
        ]);

        return (int) $sale['id'];
    };

    return ['p' => $p, 'u' => $u, 'register' => $register, 'session' => $session, 'sell' => $sell];
};
$backdate = static function (int $saleId, string $when): void {
    Database::pdo()->exec("UPDATE sales SET created_at = '{$when}' WHERE id = {$saleId}");
};
$rowFor = static function (int $productId): array {
    foreach ((new ReportService())->sellingSpeed() as $r) {
        if ($r['id'] === $productId) {
            return $r;
        }
    }
    throw new RuntimeException('product missing from sellingSpeed');
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'per day: the last 30 days net of returns, divided by the product age when younger' => function () use ($setup, $backdate, $rowFor): void {
        $s = $setup();
        // 60 pieces sold 10 days ago, 30 yesterday: first sale 10 days back → age 11, (60+30)/11 ≈ 8.2/day
        $backdate(($s['sell'])('60'), date('Y-m-d H:i:s', time() - 10 * 86400));
        $backdate(($s['sell'])('30'), date('Y-m-d H:i:s', time() - 86400));
        $r = $rowFor($s['p']);
        assert_same(8.2, $r['per_day']);
        assert_same('Piece', $r['unit']);
        assert_same(null, $r['per_month'], 'no full month behind it yet');
    },

    'per month: the average of the last 3 full months, and returns are deducted' => function () use ($setup, $backdate, $rowFor): void {
        $s = $setup();
        $m1 = date('Y-m-10 12:00:00', strtotime(date('Y-m-01') . ' -1 month'));
        $m2 = date('Y-m-10 12:00:00', strtotime(date('Y-m-01') . ' -2 months'));
        $m3 = date('Y-m-10 12:00:00', strtotime(date('Y-m-01') . ' -3 months'));
        // the first sale a month before the window, so all three months count as full ones
        $backdate(($s['sell'])('1'), date('Y-m-10 12:00:00', strtotime(date('Y-m-01') . ' -4 months')));
        $backdate(($s['sell'])('95'), $m3);
        $backdate(($s['sell'])('82'), $m2);
        $saleId = ($s['sell'])('97');
        $backdate($saleId, $m1);
        // 4 of the 97 came back inside that month: net 93 → (95 + 82 + 93) / 3 = 90/month
        $item = (int) Database::pdo()->query("SELECT id FROM sale_items WHERE sale_id = {$saleId}")->fetchColumn();
        $ret = (new \App\Services\ReturnService())->create($saleId, [['sale_item_id' => $item, 'qty' => '4', 'condition' => 'restock']],
            'MIX', 'test', $s['session'], $s['register'], TEST_ADMIN_ID, false, '12', '', 'cash');
        Database::pdo()->exec("UPDATE returns SET created_at = '" . date('Y-m-15 12:00:00', strtotime(date('Y-m-01') . ' -1 month')) . "' WHERE id = {$ret['id']}");
        assert_same(90.0, $rowFor($s['p'])['per_month']);
    },

    'the dashboard shows the panel to whoever reads reports, and not to a bare cashier' => function () use ($setup, $backdate): void {
        $s = $setup();
        $backdate(($s['sell'])('30'), date('Y-m-d H:i:s', time() - 86400));
        $c = login_as('admin', TEST_ADMIN_PASSWORD);
        $dash = $c->get('dashboard')->body;
        assert_contains('Selling speed', $dash);
        assert_contains('Nakhla Mint', $dash);
        assert_contains('Per day', $dash);
        $id = (new \App\Services\UserService())->create('ahmad', 'Ahmad', \App\Models\Role::CASHIER_ID, 'temp-pass-1');
        (new \App\Models\User())->setPassword($id, 'secret-pass-9', false);
        assert_not_contains('Selling speed', login_as('ahmad', 'secret-pass-9')->get('dashboard')->body, 'no averages for a cashier');
    },
];
