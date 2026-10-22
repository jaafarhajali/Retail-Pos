<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Customer;
use App\Models\Register;
use App\Services\CashService;
use App\Services\DebtService;
use App\Services\ProductService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-10-22 (Aya): a debt is paid at the higher of the price on the day of the sale and today's price, for EVERY
 * product sold on credit (not only "price floats" ones), and only on the part of the invoice not paid yet:
 * 10 boxes at $10 on credit, $50 paid, the box now $12 → +$10 (5 unpaid boxes × $2), he owes $60.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $coal = make_product('Coco Nara', 'piece');   // an ordinary product: price_floats is off
    $box = $svc->addUnit($coal, 'Box', '1', false, true);
    $svc->setPrices($box, '10', '9');
    (new StockService())->adjust($coal, $box, '50', 'opening', '', null, TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '500', '0');
    $customer = (new Customer())->create(['name' => 'Ahmad', 'phone' => null, 'notes' => null, 'default_price_level' => 'retail', 'credit_limit_usd' => null]);

    return ['coal' => $coal, 'box' => $box, 'register' => $register, 'session' => $session, 'customer' => $customer];
};
$sell = static fn (array $s, string $qty, array $payments): array => (new SaleService())->complete([
    'register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => $s['customer'],
    'price_level' => 'retail', 'lines' => [['product_id' => $s['coal'], 'unit_id' => $s['box'], 'qty' => $qty]], 'invoice_discount' => '',
    'payments' => $payments, 'change_currency' => 'USD', 'notes' => '', 'pin' => '',
]);
$credit = static fn (string $usd): array => ['method' => 'credit', 'currency' => 'USD', 'amount' => $usd];
$price = static function (array $s, string $retail): void { (new ProductService())->setPrices($s['box'], $retail, '9'); };
$pay = static fn (array $s, string $usd): array => (new CashService())->collectDebt($s['customer'], $usd, '', $s['session'], TEST_ADMIN_ID, 'USD');

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'every product sold on credit follows the price, not only "price floats" ones: $10 → $12 gives $12, $12 → $10 keeps $12, same price keeps it' => function () use ($setup, $sell, $credit, $price): void {
        $s = $setup();
        $sell($s, '1', [$credit('10')]);
        $debts = new DebtService();
        assert_same('0.00', $debts->pending($s['customer'])['amount'], 'same price: nothing changes');
        $price($s, '8');
        assert_same('0.00', $debts->pending($s['customer'])['amount'], 'a lower price never lowers the debt');
        $price($s, '12');
        assert_same('2.00', $debts->pending($s['customer'])['amount'], '$10 → $12: he pays $12');
    },

    'part paid: only the unpaid part follows the price — 10 boxes at $10, $50 paid, box now $12 → +$10, owes $60' => function () use ($setup, $sell, $credit, $price, $pay): void {
        $s = $setup();
        $sell($s, '10', [$credit('100')]);
        $pay($s, '50');   // at the old price
        $price($s, '12');
        $p = (new DebtService())->pending($s['customer']);
        assert_same('10.00', $p['amount']);
        assert_same('5 Box Coco Nara $10.00 → $12.00 (+$10.00)', (new DebtService())->describe($p));
        $r = $pay($s, '60');
        assert_same('0.00', $r['balance'], 'he paid $50 + $60 for 10 boxes: 5 at $10 and 5 at $12');
        $adj = Database::pdo()->query('SELECT qty, price_old, price_new, amount_usd FROM debt_adjustments')->fetch(PDO::FETCH_ASSOC);
        assert_same(['qty' => '5.000', 'price_old' => '10.00', 'price_new' => '12.00', 'amount_usd' => '10.00'], $adj);
    },

    'a sale paid partly in cash: only the part taken on credit follows the price' => function () use ($setup, $sell, $credit, $price): void {
        $s = $setup();
        $sell($s, '10', [['method' => 'cash', 'currency' => 'USD', 'amount' => '40'], $credit('60')]);   // 4 boxes paid, 6 on credit
        $price($s, '12');
        assert_same('12.00', (new DebtService())->pending($s['customer'])['amount'], '6 boxes × $2');
    },

    'returned goods are not counted twice: 10 on credit, 5 returned, box now $12 → the 5 kept and unpaid go up' => function () use ($setup, $sell, $credit, $price): void {
        $s = $setup();
        $sale = $sell($s, '10', [$credit('100')]);
        $item = (new \App\Models\Sale())->items((int) $sale['id'])[0];
        (new ReturnService())->create((int) $sale['id'], [['sale_item_id' => (int) $item['id'], 'qty' => '5', 'condition' => 'restock']], 'USD', '', $s['session'], $s['register'], TEST_ADMIN_ID);
        assert_same('50.00', (new Customer())->balance($s['customer']), 'the return took $50 off the debt');
        $price($s, '12');
        assert_same('10.00', (new DebtService())->pending($s['customer'])['amount'], '5 boxes × $2');
    },

    'the Customers list shows the same amount as the customer page: $30 sale + $2 price of the day = $32 (Aya, 2026-10-22)' => function () use ($setup, $sell, $credit, $price): void {
        $s = $setup();
        $sell($s, '3', [$credit('30')]);
        $price($s, '10.67');   // 3 boxes × $0.67 = +$2.01
        require_once __DIR__ . '/support/http.php';
        $c = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('$32.01', $c->get('customers/edit', ['id' => $s['customer']])->body, 'his page');
        $list = $c->get('customers')->body;
        assert_contains('$32.01', $list, 'the list says the same');
        assert_not_contains('$30.00', $list);
    },

    'once fully paid, an invoice is never re-priced' => function () use ($setup, $sell, $credit, $price, $pay): void {
        $s = $setup();
        $sell($s, '3', [$credit('30')]);
        $pay($s, '30');
        $price($s, '15');
        assert_same('0.00', (new DebtService())->pending($s['customer'])['amount']);
    },
];
