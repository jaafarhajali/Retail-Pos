<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\RoleService;

/** Every admin list has a bin on each row: live when the row can be deleted, greyed out (with the reason) when it cannot. */
return [
    '__before' => 'test_db_reset',

    'products and suppliers: a live bin for a row never used, a greyed one with history; the bin deletes' => function (): void {
        $svc = new PartyService();
        $typo = $svc->saveSupplier(0, 'Al Fakher Lebanun', '', '', true);
        $real = $svc->saveSupplier(0, 'Al Fakher Lebanon', '', '', true);
        $fresh = make_product('New Flavour', 'g');
        $sold = make_product('Old Flavour', 'g');
        $pack = (new ProductService())->addUnit($sold, 'Pack', '250', false, false);
        (new PurchaseService())->create($real, 'INV-1', date('Y-m-d'), [['product_id' => $sold, 'unit_id' => $pack, 'qty' => '10', 'unit_cost' => '6']], '', 'outside', '', TEST_ADMIN_ID);

        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $body = $client->get('products')->body;
        assert_contains('name="product_id" value="' . $fresh . '"', $body, 'live bin for the unused product');
        assert_not_contains('name="product_id" value="' . $sold . '"', $body, 'no form for the used product');
        assert_contains('Has sales, purchases or stock history', $body, 'the greyed bin says why');
        assert_same(302, $client->post('products/delete', ['product_id' => $fresh])->status);
        assert_same(null, (new Product())->find($fresh));
        assert_true((new Product())->find($sold) !== null);

        $body = $client->get('suppliers')->body;
        assert_contains('name="id" value="' . $typo . '"', $body, 'live bin for the unused supplier');
        assert_not_contains('name="id" value="' . $real . '"', $body);
        assert_contains('Has purchases or payments', $body);
        assert_same(302, $client->post('suppliers/delete', ['id' => $typo])->status);
        assert_same(null, (new Supplier())->find($typo));
    },

    'categories and roles: the bin is greyed for a category with products, a system role or a role with users' => function (): void {
        $empty = make_category('Empty');
        $full = make_category('Tobacco');
        make_product('Mint', 'g', $full);
        $spare = (new RoleService())->create('Spare role');
        $staffed = (new RoleService())->create('Stock keeper');
        make_user('keeper', $staffed);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);

        $body = $client->get('categories')->body;
        assert_contains('name="id" value="' . $empty . '"', $body, 'live bin for the empty category');
        assert_not_contains('name="id" value="' . $full . '"', $body);
        assert_contains('still has products', $body);
        assert_same(302, $client->post('categories/delete', ['id' => $empty])->status);
        assert_same(null, (new Category())->find($empty));

        $body = $client->get('roles')->body;
        assert_contains('name="id" value="' . $spare . '"', $body, 'live bin for the spare role');
        assert_contains('System roles cannot be deleted.', $body);
        assert_contains('assigned to users', $body, 'Stock keeper has a user');
        assert_not_contains('name="id" value="' . $staffed . '"', $body, 'no live bin for a role with users');
        assert_same(302, $client->post('roles/delete', ['id' => $spare])->status);
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM roles WHERE name = 'Spare role'")->fetchColumn());
    },

    'a user without product.manage sees no bin column on the product list' => function (): void {
        make_product('Visible', 'piece');
        make_user('viewer');
        $client = login_as('viewer', 'password123');
        assert_not_contains('btn-bin', $client->get('products')->body, 'a cashier must not see the bin');
    },
];
