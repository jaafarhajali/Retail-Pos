<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Services\CashService;
use App\Services\CountService;
use App\Services\Money;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/** Charcoal (g; kg $15, Box $280), a register and an open session with $100 + 1,000,000 LBP float. */
$setup = static function (): array {
    $svc = new ProductService();
    $charcoal = make_product('فحم', 'g');
    $kg = $svc->addUnit($charcoal, 'kg', '1000', true, false);
    $box = $svc->addUnit($charcoal, 'Box', '20000', false, true);
    $svc->setPrices($kg, '15', '13');
    $svc->setPrices($box, '280', '250');
    $svc->setCost($charcoal, '200', 20000);
    (new StockService())->adjust($charcoal, $box, '10', 'opening', '', null, TEST_ADMIN_ID);
    $registerId = (new Register())->create('Register 01');
    $sessionId = (new CashService())->open($registerId, TEST_ADMIN_ID, '100', '1000000');

    return ['charcoal' => $charcoal, 'kg' => $kg, 'box' => $box, 'register' => $registerId, 'session' => $sessionId];
};
$sale = static fn (array $s, array $lines, array $payments, array $extra = []): array => (new SaleService())->complete([
    'register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => $extra['customer_id'] ?? null,
    'price_level' => $extra['price_level'] ?? 'retail', 'lines' => $lines, 'invoice_discount' => $extra['invoice_discount'] ?? '',
    'payments' => $payments, 'change_currency' => $extra['change_currency'] ?? 'LBP', 'notes' => '', 'pin' => '',
]);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'LBP rounding: 16,000 → 15,000 · 17,000 → 15,000 · 18,000 → 20,000 · 17,500 → 20,000 (tie up)' => function (): void {
        assert_same(15000, Money::roundLbp(16000, 5000));
        assert_same(15000, Money::roundLbp(17000, 5000));
        assert_same(20000, Money::roundLbp(18000, 5000));
        assert_same(20000, Money::roundLbp(17500, 5000));
    },

    'opening stock shows as 10 Box and moving-average purchase gives $12.50 per box' => function () use ($setup): void {
        $s = $setup();
        assert_same(200000, (int) (new Product())->find($s['charcoal'])['stock_base']);
        // cost was $200/Box; buy 200,000 g (10 Box) at $50/Box: (200000×0.01 + 200000×0.0025) / 400000 = 0.00625
        (new PurchaseService())->create(null, '', date('Y-m-d'), [['product_id' => $s['charcoal'], 'unit_id' => $s['box'], 'qty' => '10', 'unit_cost' => '50']], '', 'outside', '', TEST_ADMIN_ID);
        assert_same('0.006250', (new Product())->find($s['charcoal'])['cost_per_base']);
        assert_same(400000, (int) (new Product())->find($s['charcoal'])['stock_base']);
    },

    'sale of $23 paid $20 USD + 270,000 LBP is fully paid with exact cash movements (§7.1)' => function () use ($setup, $sale): void {
        $s = $setup();
        // 1.5 kg ($22.50) + 500 g by amount ($0.50) — total 23.00
        $r = $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'qty' => '1.5'], ['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'amount_usd' => '0.50']],
            [['method' => 'cash', 'currency' => 'USD', 'amount' => '20'], ['method' => 'cash', 'currency' => 'LBP', 'amount' => '270000']]);
        assert_same('INV-000001', $r['invoice_no']);
        assert_same('23.00', $r['total_usd']);
        assert_same('0.00', $r['change_usd']);
        assert_same(0, $r['change_lbp']);
        $row = (new Sale())->find($r['id']);
        assert_same('0.00', $row['rounding_usd']);
        $items = (new Sale())->items($r['id']);
        assert_same(1500, (int) $items[0]['base_qty']);
        assert_same(33, (int) $items[1]['base_qty'], 'floor(0.50 / 0.015) = 33 g');
        assert_same('0.50', $items[1]['line_total_usd']);
        $expected = (new CashSession())->expected($s['session']);
        assert_same('120.00', $expected['USD']);
        assert_same('1270000', $expected['LBP']);
        assert_same(200000 - 1533, (int) (new Product())->find($s['charcoal'])['stock_base']);
    },

    '$50 paid for $23.20 with change in LBP gives 2,410,000 LBP and +$0.02 rounding (§7.3)' => function () use ($setup, $sale): void {
        $s = $setup();
        $r = $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'amount_usd' => '23.20']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '50']]);
        assert_same(2410000, $r['change_lbp']);
        assert_same('0.02', $r['rounding_usd']);
        $expected = (new CashSession())->expected($s['session']);
        assert_same('150.00', $expected['USD']);
        assert_same((string) (1000000 - 2410000), $expected['LBP']);
    },

    'an LBP shortfall within 2,500 LBP counts as paid and a bigger one is refused (§7.2)' => function () use ($setup, $sale): void {
        $s = $setup();
        // $23.45 → 2,110,500 LBP; customer pays 2,110,000 → 500 LBP short = tolerated (rounding −$0.01)
        $r = $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'amount_usd' => '23.45']], [['method' => 'cash', 'currency' => 'LBP', 'amount' => '2110000']]);
        assert_same('-0.01', $r['rounding_usd']);
        assert_throws(DomainException::class, fn () => $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'qty' => '1']], [['method' => 'cash', 'currency' => 'LBP', 'amount' => '1300000']]));
    },

    'an invoice discount is spread across lines so a return refunds what was really paid (§6, §9)' => function () use ($setup, $sale): void {
        $s = $setup();
        // 2 lines of $15 and $280, $10 off the invoice → 0.51 / 9.49
        $r = $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'qty' => '1'], ['product_id' => $s['charcoal'], 'unit_id' => $s['box'], 'qty' => '1']],
            [['method' => 'card', 'currency' => 'USD', 'amount' => '285']], ['invoice_discount' => '10']);
        $items = (new Sale())->items($r['id']);
        assert_same('0.51', $items[0]['line_discount_usd']);
        assert_same('9.49', $items[1]['line_discount_usd']);
        assert_same('14.49', $items[0]['line_total_usd']);
        assert_same('270.51', $items[1]['line_total_usd']);
        $ret = (new ReturnService())->create($r['id'], [['sale_item_id' => (int) $items[1]['id'], 'qty' => '1', 'condition' => 'waste']], 'USD', 'damaged', $s['session'], $s['register'], TEST_ADMIN_ID);
        assert_same('270.51', $ret['total_usd']);
        assert_same('USD', $ret['cash']['currency']);
        assert_same('270.51', $ret['cash']['amount']);
        assert_same(200000 - 20000, (int) (new Product())->find($s['charcoal'])['stock_base'], 'waste: no sellable stock back');
        $waste = Database::pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'waste' AND reason = 'returned-damaged'")->fetchColumn();
        assert_same(1, (int) $waste);
        assert_throws(DomainException::class, fn () => (new ReturnService())->create($r['id'], [['sale_item_id' => (int) $items[1]['id'], 'qty' => '1', 'condition' => 'restock']], 'USD', '', $s['session'], $s['register'], TEST_ADMIN_ID));
    },

    'credit sale adds debt, debt collection at the till reduces it and enters the drawer (§10)' => function () use ($setup, $sale): void {
        $s = $setup();
        $cust = (new Customer())->create(['name' => 'أحمد', 'phone' => null, 'notes' => null, 'default_price_level' => 'retail', 'credit_limit_usd' => null]);
        $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['box'], 'qty' => '1']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '80'], ['method' => 'credit', 'currency' => 'USD', 'amount' => '200']], ['customer_id' => $cust]);
        assert_same('200.00', (new Customer())->balance($cust));
        (new CashService())->collectDebt($cust, 'LBP', '9000000', $s['session'], TEST_ADMIN_ID);
        assert_same('100.00', (new Customer())->balance($cust));
        assert_same('10000000', (new CashSession())->expected($s['session'])['LBP']);
    },

    'void in the same session restocks and reverses cash; a stocktake applies only the difference (§12, §13)' => function () use ($setup, $sale): void {
        $s = $setup();
        $r = $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['box'], 'qty' => '2']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '560']]);
        (new SaleService())->void($r['id'], 'customer changed mind', TEST_ADMIN_ID);
        assert_same(200000, (int) (new Product())->find($s['charcoal'])['stock_base']);
        assert_same('100.00', (new CashSession())->expected($s['session'])['USD']);
        assert_same('voided', (new Sale())->find($r['id'])['status']);

        $count = (new CountService())->start('all', 0, '', TEST_ADMIN_ID);          // snapshot 200,000 g
        $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['box'], 'qty' => '1']], [['method' => 'card', 'currency' => 'USD', 'amount' => '280']]);   // sold during the count → 180,000
        $line = (new \App\Models\StockCount())->lines($count)[0];
        (new CountService())->enter($count, (int) $line['id'], [['unit_id' => $s['box'], 'qty' => '9'], ['unit_id' => $s['kg'], 'qty' => '17.5']]);   // counted 197,500 vs snapshot 200,000 → −2,500
        (new CountService())->confirm($count, TEST_ADMIN_ID);
        assert_same(177500, (int) (new Product())->find($s['charcoal'])['stock_base'], '180,000 − 2,500: the sale during the count is not lost');
    },

    'blind close computes expected vs counted per currency and a Z number' => function () use ($setup, $sale): void {
        $s = $setup();
        $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'qty' => '1']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '15']]);
        $f = (new CashService())->close($s['session'], ['100' => '1', '5' => '3'], ['100000' => '10'], TEST_ADMIN_ID);
        assert_same('115.00', $f['expected_usd']);
        assert_same('115.00', $f['counted_usd']);
        assert_same('0.00', $f['diff_usd']);
        assert_same('1000000', $f['expected_lbp']);
        assert_same('0', $f['diff_lbp']);
        assert_same('Z-000001', $f['z_no']);
        assert_throws(DomainException::class, fn () => (new CashService())->close($s['session'], [], [], TEST_ADMIN_ID));
    },
];
