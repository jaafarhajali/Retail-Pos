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

/** The one-form product page (products/save): a new product by weight with one unit sold at 15 dollars per kg. */
$productForm = static fn (array $override = []): array => $override + [
    'product_id' => '0', 'then' => 'stay', 'name' => 'فحم', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '', 'description' => '',
    'target_margin_pct' => '', 'show_on_pos_grid' => '1', 'main_unit' => 'n1',
    'units[n1][id]' => '0', 'units[n1][type]' => 'kg', 'units[n1][size]' => '', 'units[n1][size_unit]' => '', 'units[n1][retail]' => '15', 'units[n1][wholesale]' => '', 'units[n1][barcodes]' => '',
    'cost' => '', 'cost_unit' => 'n1', 'min_qty' => '', 'min_unit' => 'n1', 'opening_qty' => '', 'opening_unit' => 'n1',
];

return [
    '__before' => 'test_db_reset',

    'a cashier cannot open the product list' => function (): void {
        make_user('cashier1');
        assert_same(403, login_as('cashier1', 'password123')->get('products')->status);
    },

    // Review focus 3
    'a clerk without product.view_cost never receives cost markup and cannot post a cost' => function () use ($clerk, $productForm): void {
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
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/save', $productForm(['product_id' => (string) $id, 'name' => 'Charcoal', 'is_active' => '1', 'cost' => '1']))->status);
        assert_same('0.010000', Database::pdo()->query("SELECT cost_per_base FROM products WHERE id = {$id}")->fetchColumn(), 'a cost posted without the permission is ignored');
        assert_contains('r=products', $edit->body, 'sidebar shows Products');
        assert_not_contains('r=categories', $edit->body, 'sidebar hides Categories');
    },

    'the admin sees the cost card and the cost column' => function (): void {
        $id = make_product('Charcoal', 'g');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('data-col="cost"', $client->get('products')->body);
        assert_contains('cost-card', $client->get('products/edit', ['id' => $id])->body);
    },

    // Review focus 5
    'tampered array fields on the product form give a redirect, never a 500' => function () use ($idFrom, $productForm): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        assert_same(302, $client->post('products/save', $productForm(['name' => ['x'], 'base_unit' => ['g']]))->status);
        assert_same(200, $client->get('products/create')->status);
        $id = $idFrom($client->post('products/save', $productForm())->location());
        assert_true($id > 0, 'the product was created');
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/save', $productForm(['product_id' => (string) $id, 'is_active' => '1', 'units[n1][type]' => ['kg'], 'units[n1][size]' => ['1000']]))->status);
        assert_same(200, $client->get('products/edit', ['id' => $id])->status);
        assert_same(200, $client->get('products', ['q' => ['x'], 'stock' => ['in']])->status);
    },

    // Deactivating a category is the recommended alternative to deleting it; saving a product must not lose it.
    'saving a product keeps its deactivated category' => function () use ($productForm): void {
        $cat = make_category('Old charcoal');
        $id = make_product('Coco', 'g', $cat);
        (new \App\Services\CategoryService())->update($cat, 'Old charcoal', '', '', false);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $edit = $client->get('products/edit', ['id' => $id])->body;
        assert_contains('value="' . $cat . '" selected', $edit, 'the inactive category is still offered and selected');
        assert_contains('(inactive)', $edit);
        assert_same(302, $client->post('products/save', $productForm(['product_id' => (string) $id, 'name' => 'Coco', 'category_id' => (string) $cat, 'is_active' => '1']))->status);
        assert_same($cat, (int) Database::pdo()->query("SELECT category_id FROM products WHERE id = {$id}")->fetchColumn());
        assert_same(302, $client->post('products/save', $productForm(['product_id' => (string) $id, 'name' => 'Coco', 'category_id' => ['1'], 'is_active' => '1']))->status, 'tampered update');
        assert_same(200, $client->get('products/edit', ['id' => $id])->status);
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

    'an image is uploaded through the form, shown on the product page and served' => function () use ($productForm): void {
        $id = make_product('Charcoal', 'g');
        $img = imagecreatetruecolor(600, 600);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $edit = $client->get('products/edit', ['id' => $id]);
        assert_contains('name="image"', $edit->body, 'the photo is part of the product form');
        $response = $client->postMultipart('products/save', $productForm(['product_id' => (string) $id, 'name' => 'Charcoal', 'is_active' => '1']), ['image' => ['coal.png', $bytes, 'image/png']]);
        assert_same(302, $response->status);
        $body = $client->get('products/edit', ['id' => $id])->body;
        assert_contains('uploads/test/products/' . $id . '-', $body);
        preg_match('~(uploads/test/products/[^"]+\.jpg)~', $body, $m);
        $image = file_get_contents('http://127.0.0.1:' . TestServer::PORT . '/' . $m[1]);
        assert_same([400, 400], array_slice(getimagesizefromstring((string) $image), 0, 2));
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/save', $productForm(['product_id' => (string) $id, 'name' => 'Charcoal', 'is_active' => '1', 'remove_image' => '1']))->status);
        assert_not_contains('uploads/test/products/', $client->get('products/edit', ['id' => $id])->body);
    },
];
