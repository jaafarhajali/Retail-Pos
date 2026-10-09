<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

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
 * Owner, 2026-10-09: tobacco follows a black-market price that moves during the day. A product marked "price floats"
 * is priced on Today's prices; a return refunds the lower of paid and today's price; a debt is re-priced when paid.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $tobacco = make_product('Al Fakher', 'g');
    Database::pdo()->exec("UPDATE products SET price_floats = 1 WHERE id = {$tobacco}");
    $box = $svc->addUnit($tobacco, 'Box', '1000', false, true);
    $svc->setPrices($box, '32', '30');
    $svc->setCost($tobacco, '20', 1000);
    (new StockService())->adjust($tobacco, $box, '20', 'opening', '', null, TEST_ADMIN_ID);
    $charcoal = make_product('Charcoal', 'piece');
    $piece = $svc->addUnit($charcoal, 'Piece', '1', false, true);
    $svc->setPrices($piece, '5', '4');
    (new StockService())->adjust($charcoal, $piece, '50', 'opening', '', null, TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '500', '0');

    return ['tobacco' => $tobacco, 'box' => $box, 'charcoal' => $charcoal, 'piece' => $piece, 'register' => $register, 'session' => $session];
};
$sale = static fn (array $s, array $lines, array $payments, array $extra = []): array => (new SaleService())->complete([
    'register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => $extra['customer_id'] ?? null,
    'price_level' => 'retail', 'lines' => $lines, 'invoice_discount' => '', 'payments' => $payments, 'change_currency' => 'USD', 'notes' => '', 'pin' => '',
]);
$price = static function (array $s, string $retail): void { (new ProductService())->setPrices($s['box'], $retail, '30'); };
$customer = static fn (): int => (new Customer())->create(['name' => 'Lounge 961', 'phone' => '76 555 111', 'notes' => null, 'default_price_level' => 'retail', 'credit_limit_usd' => null]);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    "Today's prices: the page lists floating units only, Save changes them and keeps the history; the till sees a new stamp" => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('prices')->body;
        assert_contains('Al Fakher', $page);
        assert_not_contains('Charcoal', $page, 'a fixed-price product is not listed');
        assert_contains('name="retail[' . $s['box'] . ']"', $page);
        $client->get('pos');
        $before = json_decode($client->get('pos/prices')->body, true);
        assert_same(302, $client->post('prices/save', ['retail' => [$s['box'] => '35'], 'wholesale' => [$s['box'] => '30']])->status);
        assert_same('35.00', Database::pdo()->query("SELECT retail_price FROM product_units WHERE id = {$s['box']}")->fetchColumn());
        $change = Database::pdo()->query('SELECT retail_old, retail_new, wholesale_old, wholesale_new, user_id FROM price_changes ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        assert_same(['retail_old' => '32.00', 'retail_new' => '35.00', 'wholesale_old' => '30.00', 'wholesale_new' => '30.00', 'user_id' => TEST_ADMIN_ID], $change);
        $after = json_decode($client->get('pos/prices')->body, true);
        assert_true($after['stamp'] > $before['stamp'], 'the stamp moved');
        assert_same([['id' => $s['box'], 'retail' => '35.00', 'wholesale' => '30.00']], $after['units']);
        assert_contains('1 price updated', $client->get('prices')->body);
        assert_contains('$32.00 → $35.00', $client->get('prices')->body);
        // a cashier may not open it
        make_user('cashier1');
        assert_same(403, login_as('cashier1', 'password123')->get('prices')->status);
    },

    'the product page saves the Price floats tick box, and the product then shows on Today\'s prices' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        $form = ['product_id' => '0', 'then' => 'stay', 'name' => 'Mazaya', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '', 'description' => '',
            'target_margin_pct' => '', 'show_on_pos_grid' => '1', 'price_floats' => '1', 'main_unit' => 'n1',
            'units[n1][id]' => '0', 'units[n1][type]' => 'Pack', 'units[n1][size]' => '250', 'units[n1][size_unit]' => 'g', 'units[n1][retail]' => '7', 'units[n1][wholesale]' => '', 'units[n1][barcodes]' => '',
            'cost' => '', 'cost_unit' => 'n1', 'min_qty' => '', 'min_unit' => 'n1', 'opening_qty' => '', 'opening_unit' => 'n1'];
        assert_same(302, $client->post('products/save', $form)->status);
        $id = (int) Database::pdo()->query("SELECT id FROM products WHERE name = 'Mazaya'")->fetchColumn();
        assert_same(1, (int) Database::pdo()->query("SELECT price_floats FROM products WHERE id = {$id}")->fetchColumn());
        assert_contains('Mazaya', $client->get('prices')->body);
        assert_contains('id="price_floats" name="price_floats" value="1" checked', $client->get('products/edit', ['id' => $id])->body);
        // untick: it leaves the page
        unset($form['price_floats']);
        $form['product_id'] = (string) $id;
        $unit = (int) Database::pdo()->query("SELECT id FROM product_units WHERE product_id = {$id}")->fetchColumn();
        $form['units[n1][id]'] = (string) $unit;
        assert_same(302, $client->post('products/save', $form)->status);
        assert_same(0, (int) Database::pdo()->query("SELECT price_floats FROM products WHERE id = {$id}")->fetchColumn());
        assert_not_contains('name="retail[' . $unit . ']"', $client->get('prices')->body, 'no longer in the price table (its history stays)');
    },

    'a return refunds the lower of what was paid and today\'s price: $32 paid, today $35 → $32; today $30 → $30' => function () use ($setup, $sale, $price): void {
        $s = $setup();
        $r = $sale($s, [['product_id' => $s['tobacco'], 'unit_id' => $s['box'], 'qty' => '2']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '64']]);
        $saleId = (int) $r['id'];
        $item = (int) Database::pdo()->query("SELECT id FROM sale_items WHERE sale_id = {$saleId}")->fetchColumn();
        $price($s, '35');
        $ret = (new ReturnService())->create($saleId, [['sale_item_id' => $item, 'qty' => '1', 'condition' => 'restock']], 'USD', '', $s['session'], $s['register'], TEST_ADMIN_ID);
        assert_same('32.00', $ret['total_usd'], 'the price rose: he gets what he paid');
        $price($s, '30');
        $ret = (new ReturnService())->create($saleId, [['sale_item_id' => $item, 'qty' => '1', 'condition' => 'restock']], 'USD', '', $s['session'], $s['register'], TEST_ADMIN_ID);
        assert_same('30.00', $ret['total_usd'], 'the price fell: he gets today\'s price');
        assert_same('30.00', Database::pdo()->query('SELECT unit_price_usd FROM return_items ORDER BY id DESC LIMIT 1')->fetchColumn());
        // a fixed-price product is never capped
        $r2 = $sale($s, [['product_id' => $s['charcoal'], 'unit_id' => $s['piece'], 'qty' => '1']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '5']]);
        (new ProductService())->setPrices($s['piece'], '4', '4');
        $item2 = (int) Database::pdo()->query("SELECT id FROM sale_items WHERE sale_id = {$r2['id']}")->fetchColumn();
        $ret = (new ReturnService())->create((int) $r2['id'], [['sale_item_id' => $item2, 'qty' => '1', 'condition' => 'restock']], 'USD', '', $s['session'], $s['register'], TEST_ADMIN_ID);
        assert_same('5.00', $ret['total_usd']);
    },

    'a debt on floating products is re-priced when the customer pays: 3 boxes at $32, paid when $35 → $105; once only; never down' => function () use ($setup, $sale, $price, $customer): void {
        $s = $setup();
        $c = $customer();
        $sale($s, [['product_id' => $s['tobacco'], 'unit_id' => $s['box'], 'qty' => '3'], ['product_id' => $s['charcoal'], 'unit_id' => $s['piece'], 'qty' => '2']],
            [['method' => 'credit', 'currency' => 'USD', 'amount' => '106']], ['customer_id' => $c]);
        $debts = new DebtService();
        assert_same('106.00', (new Customer())->balance($c));
        assert_same('0.00', $debts->pending($c)['amount'], 'nothing pending while the price is the same');
        $price($s, '30');
        assert_same('0.00', $debts->pending($c)['amount'], 'a lower price changes nothing');
        $price($s, '35');
        $p = $debts->pending($c);
        assert_same('9.00', $p['amount']);
        assert_same('3 Box 1kg Al Fakher $32.00 → $35.00 (+$9.00)', $debts->describe($p));
        // the till shows it in what he owes
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('pos');
        $row = json_decode($client->get('pos/customers', ['q' => 'Lounge'])->body, true)['customers'][0];
        assert_same(['115.00', '9.00'], [$row['balance'], $row['pending']]);
        assert_contains('+$9.00', $client->get('customers/edit', ['id' => $c])->body);
        // he pays: the adjustment is charged first, then the payment
        $r = (new CashService())->collectDebt($c, '115', '', $s['session'], TEST_ADMIN_ID, 'USD');
        assert_same('0.00', $r['balance']);
        $ledger = Database::pdo()->query("SELECT type, amount_usd FROM customer_ledger WHERE customer_id = {$c} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        assert_same([['type' => 'sale_credit', 'amount_usd' => '106.00'], ['type' => 'price_adjustment', 'amount_usd' => '9.00'], ['type' => 'payment', 'amount_usd' => '-115.00']], $ledger);
        assert_same(1, (int) Database::pdo()->query('SELECT COUNT(*) FROM debt_adjustments')->fetchColumn());
        // the price rises again: a settled invoice is never touched, nor a line already re-priced
        $price($s, '40');
        assert_same('0.00', $debts->pending($c)['amount']);
        assert_contains('Price of the day on debts', login_as('admin', TEST_ADMIN_PASSWORD)->get('reports', ['type' => 'profit'])->body);
    },

    'only open invoices are re-priced: one paid long ago stays as it was, the new one follows the price' => function () use ($setup, $sale, $price, $customer): void {
        $s = $setup();
        $c = $customer();
        $sale($s, [['product_id' => $s['tobacco'], 'unit_id' => $s['box'], 'qty' => '1']], [['method' => 'credit', 'currency' => 'USD', 'amount' => '32']], ['customer_id' => $c]);
        (new CashService())->collectDebt($c, '32', '', $s['session'], TEST_ADMIN_ID, 'USD');
        $sale($s, [['product_id' => $s['tobacco'], 'unit_id' => $s['box'], 'qty' => '2']], [['method' => 'credit', 'currency' => 'USD', 'amount' => '64']], ['customer_id' => $c]);
        $price($s, '34');
        $p = (new DebtService())->pending($c);
        assert_same('4.00', $p['amount'], '2 boxes × $2, the paid box is not re-priced');
        assert_same(1, count($p['lines']));
    },
];
