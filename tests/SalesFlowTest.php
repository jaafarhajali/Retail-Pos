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
    'payments' => $payments, 'change_currency' => $extra['change_currency'] ?? 'LBP', 'notes' => '', 'pin' => $extra['pin'] ?? '',
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
        // The drawer holds 1,000,000 LBP: 2,410,000 of change needs lira added first (I5), as a cashier would.
        (new CashService())->cashInOut($s['session'], 'in', 'LBP', '2000000', 'small notes for change', TEST_ADMIN_ID);
        $r = $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'amount_usd' => '23.20']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '50']]);
        assert_same(2410000, $r['change_lbp']);
        assert_same('0.02', $r['rounding_usd']);
        $expected = (new CashSession())->expected($s['session']);
        assert_same('150.00', $expected['USD']);
        assert_same((string) (1000000 + 2000000 - 2410000), $expected['LBP']);
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
        $ret = (new ReturnService())->create($r['id'], [['sale_item_id' => (int) $items[1]['id'], 'qty' => '1', 'condition' => 'waste']], 'USD', 'damaged', $s['session'], $s['register'], TEST_ADMIN_ID, true);   // paid by card: the drawer has $100, the cashier adds the rest (I5)
        assert_same('270.51', $ret['total_usd']);
        assert_same('USD', $ret['cash']['currency']);
        assert_same('270.51', $ret['cash']['amount']);
        assert_same(200000 - 1000 - 20000, (int) (new Product())->find($s['charcoal'])['stock_base'], 'the kg stays sold; the wasted box is not sellable stock');
        $waste = Database::pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'waste' AND reason = 'returned-damaged'")->fetchColumn();
        assert_same(1, (int) $waste);
        assert_throws(DomainException::class, fn () => (new ReturnService())->create($r['id'], [['sale_item_id' => (int) $items[1]['id'], 'qty' => '1', 'condition' => 'restock']], 'USD', '', $s['session'], $s['register'], TEST_ADMIN_ID));
    },

    'profit report: a return with 2 items is counted once' => function () use ($setup, $sale): void {
        $s = $setup();   // 1 kg sells at $15 and costs $10; 1 Box sells at $280 and costs $200
        $r = $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'qty' => '2'], ['product_id' => $s['charcoal'], 'unit_id' => $s['box'], 'qty' => '1']],
            [['method' => 'cash', 'currency' => 'USD', 'amount' => '310']]);
        $items = (new Sale())->items($r['id']);
        $ret = (new ReturnService())->create($r['id'], [
            ['sale_item_id' => (int) $items[0]['id'], 'qty' => '1', 'condition' => 'restock'],
            ['sale_item_id' => (int) $items[1]['id'], 'qty' => '1', 'condition' => 'restock'],
        ], 'USD', 'changed mind', $s['session'], $s['register'], TEST_ADMIN_ID);
        assert_same('295.00', $ret['total_usd']);

        $p = (new \App\Services\ReportService())->profit(date('Y-m-d'), date('Y-m-d'));
        assert_same('310.00', $p['gross_sales']);
        assert_same('295.00', $p['returns'], 'the refund is counted once, not once per item');
        assert_same('15.00', $p['net_sales'], 'one kg stays sold');
        assert_same('10.00', $p['cogs'], 'the cost of both returned items comes back: 220 − 10 − 200');
        assert_same('5.00', $p['gross_profit']);
    },

    'credit sale adds debt, debt collection at the till reduces it and enters the drawer (§10)' => function () use ($setup, $sale): void {
        $s = $setup();
        $cust = (new Customer())->create(['name' => 'أحمد', 'phone' => null, 'notes' => null, 'default_price_level' => 'retail', 'credit_limit_usd' => null]);
        $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['box'], 'qty' => '1']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '80'], ['method' => 'credit', 'currency' => 'USD', 'amount' => '200']], ['customer_id' => $cust]);
        assert_same('200.00', (new Customer())->balance($cust));
        (new CashService())->collectDebt($cust, '', '9000000', $s['session'], TEST_ADMIN_ID);
        assert_same('100.00', (new Customer())->balance($cust));
        assert_same('10000000', (new CashSession())->expected($s['session'])['LBP']);
    },

    'a stocktake applies only the difference (§12)' => function () use ($setup, $sale): void {   // voids were removed 2026-10-05: a return undoes a sale
        $s = $setup();
        $count = (new CountService())->start('all', 0, '', TEST_ADMIN_ID);          // snapshot 200,000 g
        $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['box'], 'qty' => '1']], [['method' => 'card', 'currency' => 'USD', 'amount' => '280']]);   // sold during the count → 180,000
        $line = (new \App\Models\StockCount())->lines($count)[0];
        (new CountService())->enter($count, (int) $line['id'], [['unit_id' => $s['box'], 'qty' => '9'], ['unit_id' => $s['kg'], 'qty' => '17.5']]);   // counted 197,500 vs snapshot 200,000 → −2,500
        (new CountService())->confirm($count, TEST_ADMIN_ID);
        assert_same(177500, (int) (new Product())->find($s['charcoal'])['stock_base'], '180,000 − 2,500: the sale during the count is not lost');
    },

    'a sale below cost is warned about before and after, and written to the audit log' => function () use ($setup, $sale): void {
        $s = $setup();   // 1 kg sells at $15 and costs $10
        $svc = new SaleService();
        $kg = static fn (string $discount): array => [['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'qty' => '1', 'discount' => $discount]];
        assert_same([['i' => 0, 'name' => 'فحم', 'lowered' => true]], $svc->belowCost(['price_level' => 'retail', 'lines' => $kg('6'), 'invoice_discount' => '']));
        assert_same([], $svc->belowCost(['price_level' => 'retail', 'lines' => $kg('5'), 'invoice_discount' => '']), 'exactly at cost is not below it');
        assert_same(1, count($svc->belowCost(['price_level' => 'retail', 'lines' => $kg(''), 'invoice_discount' => '5.50'])), 'the invoice discount counts too');
        $two = [['product_id' => $s['charcoal'], 'unit_id' => $s['box'], 'qty' => '1'], $kg('6')[0]];
        assert_same([['i' => 1, 'name' => 'فحم', 'lowered' => true]], $svc->belowCost(['price_level' => 'retail', 'lines' => $two, 'invoice_discount' => '']), 'only the discounted line');

        $r = $sale($s, $kg('6'), [['method' => 'cash', 'currency' => 'USD', 'amount' => '9']]);
        assert_contains('was sold below cost', implode(' ', $r['warnings']));
        $audit = \App\Core\Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'sale.below_cost'")->fetchColumn();
        assert_same(['فحم'], json_decode((string) $audit, true)['products']);
        $fine = $sale($s, $kg('1'), [['method' => 'cash', 'currency' => 'USD', 'amount' => '14']]);
        assert_same([], $fine['warnings']);
    },

    'below cost needs the administrator even inside the allowed percentage; a list price under cost only warns' => function () use ($setup, $sale): void {
        $s = $setup();   // 1 kg sells at $15 and costs $10
        (new \App\Models\Setting())->setMany(['max_cashier_discount_pct' => '50']);
        \App\Core\Settings::flush();
        (new \App\Models\User())->setPin(TEST_ADMIN_ID, '2468');
        Auth::login(make_user('cashier1'));
        $kg = static fn (string $discount): array => [['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'qty' => '1', 'discount' => $discount]];

        $fine = $sale($s, $kg('3'), [['method' => 'cash', 'currency' => 'USD', 'amount' => '12']]);   // 20 %, still above cost
        assert_same([], $fine['warnings']);

        $refused = assert_throws(DomainException::class, fn () => $sale($s, $kg('6'), [['method' => 'cash', 'currency' => 'USD', 'amount' => '9']]));   // 40 % <= 50 %, but $9 < $10
        assert_contains('Needs an Admin PIN: sale below cost', $refused->getMessage());
        $approved = $sale($s, $kg('6'), [['method' => 'cash', 'currency' => 'USD', 'amount' => '9']], ['pin' => '2468']);
        assert_contains('was sold below cost', implode(' ', $approved['warnings']));

        Auth::login(TEST_ADMIN_ID);
        (new ProductService())->setCost($s['charcoal'], '400', 20000);   // the cost rose to $20 per kg; the price is still $15
        Auth::login((int) \App\Core\Database::pdo()->query("SELECT id FROM users WHERE username = 'cashier1'")->fetchColumn());
        $list = $sale($s, $kg(''), [['method' => 'cash', 'currency' => 'USD', 'amount' => '15']]);
        assert_contains('was sold below cost', implode(' ', $list['warnings']), 'warned, not blocked: the cashier lowered nothing');
        assert_same([['i' => 0, 'name' => 'فحم', 'lowered' => false]], (new SaleService())->belowCost(['price_level' => 'retail', 'lines' => $kg(''), 'invoice_discount' => '']));
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
