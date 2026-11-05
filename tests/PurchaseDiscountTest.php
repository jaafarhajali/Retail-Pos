<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Supplier;
use App\Services\ProductService;
use App\Services\PurchaseService;

/**
 * 2026-11-05 (Aya): the supplier's discount on a purchase. Typed as dollars ("5") or a percent ("5%") on the New
 * purchase form; the total owed is subtotal − discount, and the discount is spread over the lines, so every item
 * enters stock at the price really paid — stock value and profit stay right ($100 − $5: a $10 box costs $9.50).
 */
$setup = static function (): array {
    $svc = new ProductService();
    $p = make_product('Nakhla');
    $box = $svc->addUnit($p, 'Box', '10', false, true);   // 10 pieces per box
    $supplier = (new Supplier())->create('Al Mina Tobacco', null, null);

    return [$p, $box, $supplier];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    '$5 off a $100 purchase: owed 95, each item enters stock at its real cost' => function () use ($setup): void {
        [$p, $box, $supplier] = $setup();
        $id = (new PurchaseService())->create($supplier, 'SUP-9', date('Y-m-d'),
            [['product_id' => $p, 'unit_id' => $box, 'qty' => '10', 'unit_cost' => '10']], '', 'outside', '', TEST_ADMIN_ID, '5');
        $row = Database::pdo()->query("SELECT total_usd, discount_usd FROM purchases WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        assert_same(['total_usd' => '95.00', 'discount_usd' => '5.00'], $row);
        // the box cost $10 gross, $9.50 really: $0.95 per piece into stock, on the purchase line and the movement
        assert_same('0.950000', (string) Database::pdo()->query("SELECT cost_per_base FROM purchase_items WHERE purchase_id = {$id}")->fetchColumn());
        assert_same('0.950000', (string) Database::pdo()->query("SELECT cost_per_base FROM stock_movements WHERE ref_type = 'purchase' AND ref_id = {$id}")->fetchColumn());
        assert_same('95.00', (string) (new Supplier())->find($supplier)['balance_usd'], 'the supplier is owed the discounted total');
        assert_contains('"discount":"5.00"', (string) Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'purchase.posted'")->fetchColumn());
    },

    'typed as a percent: "5%" of $100 is the same $5; refused when it eats the whole invoice' => function () use ($setup): void {
        [$p, $box, $supplier] = $setup();
        $svc = new PurchaseService();
        $id = $svc->create($supplier, '', date('Y-m-d'), [['product_id' => $p, 'unit_id' => $box, 'qty' => '10', 'unit_cost' => '10']], '', 'outside', '', TEST_ADMIN_ID, '5%');
        assert_same('5.00', (string) Database::pdo()->query("SELECT discount_usd FROM purchases WHERE id = {$id}")->fetchColumn());

        $line = [['product_id' => $p, 'unit_id' => $box, 'qty' => '1', 'unit_cost' => '10']];
        $e = assert_throws(DomainException::class, fn () => $svc->create($supplier, '', date('Y-m-d'), $line, '', 'outside', '', TEST_ADMIN_ID, '10'));
        assert_contains('cannot be the whole invoice', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->create($supplier, '', date('Y-m-d'), $line, '', 'outside', '', TEST_ADMIN_ID, '120%'));
        assert_contains('between 0 and 100', $e->getMessage());
        // paid now is checked against the discounted total
        $e = assert_throws(DomainException::class, fn () => $svc->create($supplier, '', date('Y-m-d'), $line, '10', 'outside', '', TEST_ADMIN_ID, '2'));
        assert_contains('Paid now cannot exceed', $e->getMessage());
    },

    'through the browser: the form has the box, the purchase page shows Subtotal − Discount, the report its column' => function () use ($setup): void {
        [$p, $box, $supplier] = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('name="discount"', $client->get('purchases/create')->body);
        $r = $client->post('purchases/store', ['supplier_id' => (string) $supplier, 'supplier_invoice_ref' => '', 'purchase_date' => date('Y-m-d'), 'notes' => '', 'paid_now' => '', 'discount' => '5',
            'lines[0][product_id]' => (string) $p, 'lines[0][unit_id]' => (string) $box, 'lines[0][qty]' => '10', 'lines[0][unit_cost]' => '10']);
        assert_same(302, $r->status, $r->body);
        $page = $client->get('purchases/view', ['id' => 1])->body;
        assert_contains('Subtotal', $page);
        assert_contains('$100.00', $page);
        assert_contains('−$5.00', $page);
        assert_contains('$95.00', $page);
        $report = $client->get('reports', ['type' => 'purchases'])->body;
        assert_contains('<th class="text-end">Discount</th>', $report);
        assert_contains('−$5.00', $report);
        assert_contains('$95.00', $report);
    },
];
