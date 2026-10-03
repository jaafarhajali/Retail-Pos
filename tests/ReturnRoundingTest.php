<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\Customer;
use App\Models\Register;
use App\Models\Sale;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * I4: a refund paid in LBP is rounded to 5,000 like change, and the difference is recorded on the return
 * (returns.rounding_usd), so reports and the drawer agree to the cent. RTN-000001: $7.24 → 650,000 LBP = $7.22, +$0.02.
 */
$setup = static function (): array {
    $p = make_product('Adalya Love 66');
    $pack = (new ProductService())->addUnit($p, 'Pack', '1', false, true);
    (new ProductService())->setPrices($pack, '7.24', '');
    (new StockService())->adjust($p, $pack, '10', 'opening', '', '5', TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '1000000');

    return ['p' => $p, 'pack' => $pack, 'register' => $register, 'session' => $session];
};
$sell = static function (array $s, array $payments, ?int $customer = null): int {
    $r = (new SaleService())->complete([
        'register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'retail',
        'lines' => [['product_id' => $s['p'], 'unit_id' => $s['pack'], 'qty' => '1']], 'invoice_discount' => '', 'payments' => $payments,
        'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
    ]);

    return (int) $r['id'];
};
$giveBack = static function (array $s, int $saleId, string $currency): array {
    $item = (new Sale())->items($saleId)[0];

    return (new ReturnService())->create($saleId, [['sale_item_id' => (int) $item['id'], 'qty' => '1', 'condition' => 'restock']], $currency, 'wrong flavour', $s['session'], $s['register'], TEST_ADMIN_ID);
};
$row = static fn (int $returnId): array => Database::pdo()->query('SELECT total_usd, rounding_usd FROM returns WHERE id = ' . $returnId)->fetch();

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a refund paid in LBP records what the 5,000 rounding kept (RTN-000001: $7.24 → 650,000 LBP, +$0.02)' => function () use ($setup, $sell, $giveBack, $row): void {
        $s = $setup();
        $ret = $giveBack($s, $sell($s, [['method' => 'cash', 'currency' => 'USD', 'amount' => '7.24']]), 'LBP');
        assert_same(['currency' => 'LBP', 'amount' => '650000'], $ret['cash']);
        assert_same(['total_usd' => '7.24', 'rounding_usd' => '0.02'], $row((int) $ret['id']));
        assert_same('0.02', $ret['rounding_usd']);
    },

    'a refund paid in USD has no rounding' => function () use ($setup, $sell, $giveBack, $row): void {
        $s = $setup();
        $ret = $giveBack($s, $sell($s, [['method' => 'cash', 'currency' => 'USD', 'amount' => '7.24']]), 'USD');
        assert_same('0.00', $row((int) $ret['id'])['rounding_usd']);
    },

    'with debt reduced first, only the cash part is rounded' => function () use ($setup, $sell, $giveBack, $row): void {
        $s = $setup();
        $customer = (new Customer())->create(['name' => 'Ahmad Saleh', 'phone' => null, 'notes' => null, 'default_price_level' => 'retail', 'credit_limit_usd' => null]);
        // $7.24 sale: $5 cash, $2.24 on credit. The return clears the $2.24 debt and pays $5.00 in LBP: 450,000 exactly, nothing kept.
        $ret = $giveBack($s, $sell($s, [['method' => 'cash', 'currency' => 'USD', 'amount' => '5'], ['method' => 'credit', 'currency' => 'USD', 'amount' => '2.24']], $customer), 'LBP');
        assert_same('2.24', $ret['debt_reduction']);
        assert_same(['currency' => 'LBP', 'amount' => '450000'], $ret['cash']);
        assert_same('0.00', $row((int) $ret['id'])['rounding_usd']);
    },

    'the profit report and the X/Z report count the return rounding' => function () use ($setup, $sell, $giveBack): void {
        $s = $setup();
        $giveBack($s, $sell($s, [['method' => 'cash', 'currency' => 'USD', 'amount' => '7.24']]), 'LBP');
        $p = (new ReportService())->profit(date('Y-m-d'), date('Y-m-d'));
        assert_same('7.24', $p['returns']);
        assert_same('0.02', $p['rounding']);
        assert_same('0.02', $p['net_sales'], 'sold $7.24, refunded $7.24, kept $0.02 of rounding');
        assert_same('0.02', (new CashService())->report($s['session'])['sales']['rounding']);
    },
];
