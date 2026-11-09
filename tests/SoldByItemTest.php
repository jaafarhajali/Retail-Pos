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
 * 2026-11-09 (Aya, from the owner, replacing the averages of 2026-11-07): the dashboard's Items sold
 * panel shows the REAL quantities of each item for one chosen day, or one chosen month — no averages.
 * Net of the returns recorded in the same period, in the item's sale unit; only items that moved appear.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $p = make_product('Nakhla Mint');
    $u = $svc->addUnit($p, 'Piece', '1', false, true);
    $svc->setPrices($u, '3.00', '');
    (new StockService())->adjust($p, $u, '500', 'opening', '', '1', TEST_ADMIN_ID);
    $other = make_product('Al Fakher');
    $otherU = $svc->addUnit($other, 'Piece', '1', false, true);
    $svc->setPrices($otherU, '5.00', '');
    (new StockService())->adjust($other, $otherU, '500', 'opening', '', '1', TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '1000000');
    $sell = static function (int $productId, int $unitId, string $qty) use ($register, $session): int {
        $sale = (new SaleService())->complete([
            'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $productId, 'unit_id' => $unitId, 'qty' => $qty]], 'invoice_discount' => '',
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '3000']], 'change_currency' => 'USD', 'notes' => '', 'pin' => '',
        ]);

        return (int) $sale['id'];
    };

    return ['p' => $p, 'u' => $u, 'other' => $other, 'otherU' => $otherU, 'register' => $register, 'session' => $session, 'sell' => $sell];
};
$backdate = static function (int $saleId, string $when): void {
    Database::pdo()->exec("UPDATE sales SET created_at = '{$when}' WHERE id = {$saleId}");
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'one day: the real quantities of that day alone, returns of the day deducted' => function () use ($setup, $backdate): void {
        $s = $setup();
        $day = date('Y-m-d', time() - 5 * 86400);
        $saleId = ($s['sell'])($s['p'], $s['u'], '12');
        $backdate($saleId, $day . ' 11:00:00');
        $backdate(($s['sell'])($s['other'], $s['otherU'], '4'), $day . ' 15:00:00');
        $backdate(($s['sell'])($s['p'], $s['u'], '9'), date('Y-m-d 12:00:00', time() - 4 * 86400));   // another day: not counted
        // 2 of the 12 come back the same day
        $item = (int) Database::pdo()->query("SELECT id FROM sale_items WHERE sale_id = {$saleId}")->fetchColumn();
        $ret = (new \App\Services\ReturnService())->create($saleId, [['sale_item_id' => $item, 'qty' => '2', 'condition' => 'restock']],
            'MIX', 'test', $s['session'], $s['register'], TEST_ADMIN_ID, false, '6', '', 'cash');
        Database::pdo()->exec("UPDATE returns SET created_at = '{$day} 18:00:00' WHERE id = {$ret['id']}");
        $rows = (new ReportService())->soldByItem($day, $day);
        assert_same([['Nakhla Mint', 10.0], ['Al Fakher', 4.0]], array_map(static fn (array $r): array => [$r['name'], $r['qty']], $rows));
    },

    'one month: everything sold inside that month, nothing from outside it' => function () use ($setup, $backdate): void {
        $s = $setup();
        $month = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $backdate(($s['sell'])($s['p'], $s['u'], '40'), $month . '-05 10:00:00');
        $backdate(($s['sell'])($s['p'], $s['u'], '25'), $month . '-20 10:00:00');
        $backdate(($s['sell'])($s['other'], $s['otherU'], '7'), date('Y-m-d 10:00:00'));   // this month: not counted
        $rows = (new ReportService())->soldByItem($month . '-01', date('Y-m-t', strtotime($month . '-01')));
        assert_same([['Nakhla Mint', 65.0]], array_map(static fn (array $r): array => [$r['name'], $r['qty']], $rows));
    },

    'the dashboard: Day and Month views for whoever reads reports, not for a bare cashier' => function () use ($setup, $backdate): void {
        $s = $setup();
        ($s['sell'])($s['p'], $s['u'], '12');
        $day = date('Y-m-d', time() - 5 * 86400);
        $backdate(($s['sell'])($s['other'], $s['otherU'], '4'), $day . ' 15:00:00');
        $c = login_as('admin', TEST_ADMIN_PASSWORD);
        $dash = $c->get('dashboard')->body;   // today by default
        assert_contains('Items sold', $dash);
        assert_contains('Nakhla Mint', $dash);
        $picked = $c->get('dashboard', ['sold' => 'day', 'date' => $day])->body;
        assert_contains('Al Fakher', $picked);
        assert_not_contains('Nakhla Mint', $picked, 'the other day\'s sale stays out');
        $monthly = $c->get('dashboard', ['sold' => 'month', 'month' => date('Y-m')])->body;
        assert_contains('Nakhla Mint', $monthly);
        // switching to Month while looking at a day stays in that day's month (Aya, 2026-11-09)
        $followed = $c->get('dashboard', ['sold' => 'month', 'date' => $day])->body;
        assert_contains('Everything sold in ' . date('F Y', strtotime($day)), $followed);
        assert_contains('Al Fakher', $followed);
        $id = (new \App\Services\UserService())->create('ahmad', 'Ahmad', \App\Models\Role::CASHIER_ID, 'temp-pass-1');
        (new \App\Models\User())->setPassword($id, 'secret-pass-9', false);
        assert_not_contains('Items sold', login_as('ahmad', 'secret-pass-9')->get('dashboard')->body, 'no sales numbers for a cashier');
    },
];
