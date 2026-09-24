<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Models\Permission;
use App\Models\Role;
use App\Services\ProductService;

/** A "Clerk" role: sees and manages products but never cost or prices. */
$clerk = static function (): HttpClient {
    $roleId = (new Role())->create('Clerk');
    (new Permission())->setForRole($roleId, ['product.view', 'product.manage']);
    make_user('clerk1', $roleId);

    return login_as('clerk1', 'password123');
};

/** The id in "index.php?r=products/edit&id=7". */
$idFrom = static function (string $location): int {
    preg_match('/[?&]id=(\d+)/', $location, $m);

    return (int) ($m[1] ?? 0);
};

$productForm = static fn (array $override = []): array => array_merge([
    'name' => 'فحم', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '', 'description' => '',
    'target_margin_pct' => '', 'show_on_pos_grid' => '1',
], $override);

return [
    '__before' => 'test_db_reset',

    'a cashier cannot open the product list' => function (): void {
        make_user('cashier1');
        assert_same(403, login_as('cashier1', 'password123')->get('products')->status);
    },

    // Review focus 3
    'a clerk without product.view_cost never receives cost markup and cannot post a cost' => function () use ($clerk): void {
        $id = make_product('Charcoal', 'g');
        (new ProductService())->setCost($id, '10', 1000);
        $client = $clerk();
        $list = $client->get('products');
        assert_same(200, $list->status);
        assert_not_contains('data-col="cost"', $list->body);
        assert_not_contains('0.010000', $list->body);
        $edit = $client->get('products/edit', ['id' => $id]);
        assert_same(200, $edit->status);
        assert_not_contains('cost-card', $edit->body);
        assert_not_contains('0.010000', $edit->body);
        assert_same(403, $client->post('products/cost', ['product_id' => $id, 'unit_cost' => '1', 'unit_id' => '0'])->status);
        assert_same('0.010000', Database::pdo()->query("SELECT cost_per_base FROM products WHERE id = {$id}")->fetchColumn());
        assert_contains('r=products', $edit->body, 'sidebar shows Products');
        assert_not_contains('r=categories', $edit->body, 'sidebar hides Categories');
    },

    'the admin sees the cost card and the cost column' => function (): void {
        $id = make_product('Charcoal', 'g');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('data-col="cost"', $client->get('products')->body);
        assert_contains('cost-card', $client->get('products/edit', ['id' => $id])->body);
    },

    // The phase's "done when", through the real forms.
    'an admin defines charcoal as g + kg + Box with its own prices and finds it by code' => function () use ($idFrom, $productForm): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        $response = $client->post('products/store', $productForm());
        assert_same(302, $response->status);
        $id = $idFrom($response->location());
        assert_true($id > 0, 'redirects to the edit page');

        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/unit-store', ['product_id' => $id, 'unit_name' => 'kg', 'unit_factor' => '1000', 'allows_fraction' => '1'])->status);
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/unit-store', ['product_id' => $id, 'unit_name' => 'Box', 'unit_factor' => '20,000', 'is_display' => '1'])->status);
        $box = (int) Database::pdo()->query("SELECT id FROM product_units WHERE product_id = {$id} AND name = 'Box'")->fetchColumn();
        $kg = (int) Database::pdo()->query("SELECT id FROM product_units WHERE product_id = {$id} AND name = 'kg'")->fetchColumn();

        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/prices', ['product_id' => $id, 'unit_id' => $box, 'retail_price' => '280', 'wholesale_price' => '250'])->status);
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/prices', ['product_id' => $id, 'unit_id' => $kg, 'retail_price' => '15', 'wholesale_price' => ''])->status);
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/cost', ['product_id' => $id, 'unit_id' => $box, 'unit_cost' => '200'])->status);

        $edit = $client->get('products/edit', ['id' => $id])->body;
        assert_contains('280.00', $edit);
        assert_contains('$0.010000', $edit, 'cost per g');
        assert_contains('40.0', $edit, 'Box margin: (280 − 200) / 200');

        $list = $client->get('products', ['q' => 'P-000001'])->body;
        assert_contains('فحم', $list);
        assert_contains('$280.00', $list);
        assert_contains('$15.00', $list);
        assert_contains('0 kg', $list, 'stock shown in the smallest unit');
    },

    // Review focus 2
    'a scanned barcode (with its Enter) is stored clean and found by the search box' => function () use ($idFrom, $productForm): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        $id = $idFrom($client->post('products/store', $productForm(['name' => 'Al Fakher Apple', 'base_unit' => 'piece']))->location());
        $client->get('products/edit', ['id' => $id]);
        $client->post('products/unit-store', ['product_id' => $id, 'unit_name' => 'Piece', 'unit_factor' => '1']);
        $unit = (int) Database::pdo()->query("SELECT id FROM product_units WHERE product_id = {$id}")->fetchColumn();
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/barcode-store', ['product_id' => $id, 'unit_id' => $unit, 'barcode' => "6291041500213\r\n"])->status);
        assert_same('6291041500213', Database::pdo()->query('SELECT barcode FROM barcodes')->fetchColumn());
        assert_contains('Al Fakher Apple', $client->get('products', ['q' => "6291041500213\n"])->body);

        $client->get('products/create');
        $other = $idFrom($client->post('products/store', $productForm(['name' => 'Other', 'base_unit' => 'piece']))->location());
        $client->get('products/edit', ['id' => $other]);
        $client->post('products/unit-store', ['product_id' => $other, 'unit_name' => 'Piece', 'unit_factor' => '1']);
        $otherUnit = (int) Database::pdo()->query("SELECT id FROM product_units WHERE product_id = {$other}")->fetchColumn();
        $client->get('products/edit', ['id' => $other]);
        $client->post('products/barcode-store', ['product_id' => $other, 'unit_id' => $otherUnit, 'barcode' => '6291041500213']);
        assert_contains('already used by Al Fakher Apple', $client->get('products/edit', ['id' => $other])->body);
    },

    // Review focus 5
    'tampered array fields on the product forms give a redirect, never a 500' => function () use ($idFrom, $productForm): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        assert_same(302, $client->post('products/store', $productForm(['name' => ['x'], 'base_unit' => ['g']]))->status);
        assert_same(200, $client->get('products/create')->status);
        $id = $idFrom($client->post('products/store', $productForm())->location());
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/unit-store', ['product_id' => $id, 'unit_name' => ['kg'], 'unit_factor' => ['1000']])->status);
        assert_same(200, $client->get('products/edit', ['id' => $id])->status);
        assert_same(200, $client->get('products', ['q' => ['x'], 'stock' => ['in']])->status);
    },

    'the list filters by category and stock status and paginates' => function (): void {
        $cat = make_category('Charcoal');
        $a = make_product('Coco charcoal', 'g', $cat);
        (new ProductService())->addUnit($a, 'kg', '1000', true, false);
        for ($i = 1; $i <= 26; $i++) {
            make_product(sprintf('Hose %02d', $i));
        }
        Database::pdo()->exec("UPDATE products SET stock_base = 5000 WHERE id = {$a}");
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $body = $client->get('products', ['category_id' => $cat])->body;
        assert_contains('Coco charcoal', $body);
        assert_not_contains('Hose 01', $body);
        assert_contains('5 kg', $body);
        $body = $client->get('products', ['stock' => 'out'])->body;
        assert_not_contains('Coco charcoal', $body);
        assert_contains('Hose 01', $body);
        $page2 = $client->get('products', ['page' => 2])->body;
        assert_contains('Page 2 of 2', $page2);
    },

    'the printable table lists every active product by category, with values for the admin only' => function () use ($clerk): void {
        $cat = make_category('Charcoal');
        $a = make_product('Coco charcoal', 'g', $cat);
        $b = make_product('Hose');
        $inactive = make_product('Old item');
        (new ProductService())->addUnit($a, 'kg', '1000', true, false);
        (new ProductService())->setCost($a, '10', 1000);
        Database::pdo()->exec("UPDATE products SET stock_base = 5000 WHERE id = {$a}");
        Database::pdo()->exec("UPDATE products SET is_active = 0 WHERE id = {$inactive}");

        $admin = login_as('admin', TEST_ADMIN_PASSWORD)->get('products/print');
        assert_same(200, $admin->status);
        assert_contains('Coco charcoal', $admin->body);
        assert_contains('Hose', $admin->body);
        assert_not_contains('Old item', $admin->body);
        assert_contains('5 kg', $admin->body);
        assert_contains('$50.00', $admin->body, 'stock value 5000 g × $0.01');
        assert_contains('Total stock value', $admin->body);
        assert_not_contains('app-side', $admin->body, 'no sidebar on the print layout');
        assert_contains('window.print()', $admin->body);

        $clerkPage = $clerk()->get('products/print');
        assert_same(200, $clerkPage->status);
        assert_not_contains('Stock value', $clerkPage->body);
        assert_not_contains('$50.00', $clerkPage->body);
    },

    'the product list links to the printable table' => function (): void {
        assert_contains('r=products/print', login_as('admin', TEST_ADMIN_PASSWORD)->get('products')->body);
    },
];
