<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\ProductService;
use App\Services\StockService;

/** The product page through the browser: one form that posts everything to products/save. */
$form = static fn (array $over = []): array => $over + [
    'product_id' => '0', 'then' => 'stay', 'name' => 'Coconut charcoal', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '', 'description' => '',
    'target_margin_pct' => '', 'show_on_pos_grid' => '1', 'main_unit' => 'n1',
    'units[n1][id]' => '0', 'units[n1][type]' => 'kg', 'units[n1][size]' => '', 'units[n1][size_unit]' => '', 'units[n1][retail]' => '15', 'units[n1][wholesale]' => '13', 'units[n1][barcodes]' => '',
    'units[n2][id]' => '0', 'units[n2][type]' => 'Box', 'units[n2][size]' => '20', 'units[n2][size_unit]' => 'kg', 'units[n2][retail]' => '280', 'units[n2][wholesale]' => '250', 'units[n2][barcodes]' => '6291041500213',
    'cost' => '200', 'cost_unit' => 'n2', 'min_qty' => '2', 'min_unit' => 'n2', 'opening_qty' => '10', 'opening_unit' => 'n2',
];
$png = static function (): string {
    $img = imagecreatetruecolor(600, 600);
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
};

return [
    '__before' => 'test_db_reset',

    'adding a product is one form and one save, photo included' => function () use ($form, $png): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('products/create')->body;
        assert_contains('action="index.php?r=products/save"', str_replace('%2F', '/', $page));
        assert_contains('enctype="multipart/form-data"', $page);
        assert_same(1, substr_count($page, '<form method="post"') - substr_count($page, 'auth/logout'), 'one form on the page (besides signing out)');
        foreach (['products/unit-store', 'products/unit-update', 'products/prices', 'products/cost', 'products/barcode-store', 'products/image-store', 'Save unit', 'Save prices', 'Save cost'] as $old) {
            assert_not_contains($old, str_replace('%2F', '/', $page), "the page no longer has {$old}");
        }
        assert_contains('By weight', $page);
        assert_not_contains('Base unit', $page);
        assert_not_contains('Sold in fractions', $page);

        $r = $client->postMultipart('products/save', $form(), ['image' => ['coal.png', $png(), 'image/png']]);
        assert_same(302, $r->status);
        assert_contains('products/edit', str_replace('%2F', '/', $r->location()));
        $p = Database::pdo()->query('SELECT * FROM products')->fetch();
        assert_same('Coconut charcoal', $p['name']);
        assert_same(200000, (int) $p['stock_base']);
        assert_same('0.010000', $p['cost_per_base']);
        assert_same(40000, (int) $p['min_stock_base']);
        assert_true($p['image_file'] !== null, 'the photo came with the same save');

        $edit = $client->get('products/edit', ['id' => $p['id']])->body;
        assert_contains('Coconut charcoal was added.', $edit);
        assert_contains('value="280.00"', $edit);
        assert_contains('value="6291041500213"', $edit);
        assert_contains('<strong>$10.00</strong> per kg', $edit, 'the cost reads per kg, not per gram with six decimals');
        assert_contains('$200.00 per Box 20kg', $edit);
        assert_not_contains('0.010000</', $edit);
        assert_contains('Now <strong>10 Box 20kg</strong>', $edit, 'the page shows the stock');
        assert_contains('stock/adjust', str_replace('%2F', '/', $edit));
        assert_not_contains('name="opening_qty"', $edit, 'with stock history there is no opening stock to type');
        assert_contains('uploads/test/products/' . $p['id'] . '-', $edit);
    },

    'Save and add another goes to an empty page' => function () use ($form): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        $r = $client->post('products/save', $form(['then' => 'add']));
        assert_same(302, $r->status);
        assert_contains('products/create', str_replace('%2F', '/', $r->location()));
        $next = $client->get('products/create')->body;
        assert_contains('Coconut charcoal was added.', $next);
        assert_contains('name="name" dir="auto" required maxlength="150" value=""', $next);
    },

    'a refused save keeps everything that was typed' => function () use ($form): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        $r = $client->post('products/save', $form(['units[n2][size]' => '', 'name' => 'Charcoal typed once']));
        assert_same(302, $r->status);
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn());
        $back = $client->get('products/create')->body;
        assert_contains('How much does one Box hold?', $back);
        assert_contains('value="Charcoal typed once"', $back);
        assert_contains('data-key="n2"', $back);
        assert_contains('name="units[n2][retail]" inputmode="decimal" value="280"', $back);
        assert_contains('name="units[n2][barcodes]" value="6291041500213"', $back);
        assert_contains('name="cost" inputmode="decimal" value="200"', $back);
        assert_contains('value="g" checked', $back, 'still by weight');
    },

    'the same name: a tick box appears and the second save goes through' => function () use ($form): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        $client->post('products/save', $form());
        $client->get('products/create');
        $again = $form(['units[n2][barcodes]' => '', 'opening_qty' => '']);
        assert_not_contains('allow_same_name', $client->get('products/create')->body);
        $client->post('products/save', $again);
        $back = $client->get('products/create')->body;
        assert_contains('Another product is called Coconut charcoal (code P-000001)', $back);
        assert_contains('name="allow_same_name"', $back);
        assert_same(1, (int) Database::pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn());
        $client->post('products/save', $again + ['allow_same_name' => '1']);
        assert_same(2, (int) Database::pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn());
    },

    'editing: one save changes name, price and barcode; a locked size and a used unit say why' => function () use ($form): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        $client->post('products/save', $form());
        $id = (int) Database::pdo()->query('SELECT id FROM products')->fetchColumn();
        $units = array_column((new ProductUnit())->forProduct($id), null, 'name');
        $kg = (int) $units['kg']['id'];
        $box = (int) $units['Box 20kg']['id'];
        $page = $client->get('products/edit', ['id' => $id])->body;
        assert_contains('data-key="u' . $box . '" data-locked="1"', $page, 'the opening stock is history: sizes are locked');
        assert_contains('This cannot change once the product exists', $page);

        $edit = static fn (array $over = []): array => $over + [
            'product_id' => (string) $id, 'then' => 'stay', 'name' => 'Coconut charcoal premium', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => 'P-000001', 'description' => '',
            'target_margin_pct' => '40', 'show_on_pos_grid' => '1', 'is_active' => '1', 'main_unit' => "u{$box}",
            "units[u{$kg}][id]" => (string) $kg, "units[u{$kg}][type]" => 'kg', "units[u{$kg}][retail]" => '16', "units[u{$kg}][wholesale]" => '13', "units[u{$kg}][barcodes]" => 'KG-1',
            "units[u{$box}][id]" => (string) $box, "units[u{$box}][type]" => 'Box', "units[u{$box}][size]" => '20', "units[u{$box}][size_unit]" => 'kg', "units[u{$box}][retail]" => '285', "units[u{$box}][wholesale]" => '250', "units[u{$box}][barcodes]" => '6291041500213',
            'cost' => '', 'cost_unit' => "u{$box}", 'min_qty' => '3', 'min_unit' => "u{$box}",
        ];
        assert_same(302, $client->post('products/save', $edit())->status);
        $p = (new Product())->find($id);
        $units = array_column((new ProductUnit())->forProduct($id), null, 'name');
        assert_same('Coconut charcoal premium', $p['name']);
        assert_same('16.00', $units['kg']['retail_price']);
        assert_same('285.00', $units['Box 20kg']['retail_price']);
        assert_same(1, (int) $units['Box 20kg']['is_default_sale'], 'the till now shows the box');
        assert_same(60000, (int) $p['min_stock_base']);
        assert_same('0.010000', $p['cost_per_base']);
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM barcodes WHERE barcode = 'KG-1'")->fetchColumn());

        $client->get('products/edit', ['id' => $id]);
        $client->post('products/save', $edit(["units[u{$box}][size]" => '25', 'name' => 'Not saved']));
        $back = $client->get('products/edit', ['id' => $id])->body;
        assert_contains('Box 20kg cannot change its size', $back);
        assert_same('Coconut charcoal premium', (new Product())->find($id)['name']);
    },

    'a product that was never used can be deleted from its page; one with history cannot' => function () use ($form): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        $client->post('products/save', $form(['opening_qty' => '', 'name' => 'Typed by mistake']));
        $id = (int) Database::pdo()->query('SELECT id FROM products')->fetchColumn();
        $page = $client->get('products/edit', ['id' => $id])->body;
        assert_contains('Delete this product', $page);
        assert_contains('name="opening_qty"', $page, 'no stock history yet: the opening stock can still be typed');
        assert_same(302, $client->post('products/delete', ['product_id' => $id])->status);
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn());
        assert_contains('Typed by mistake was deleted.', $client->get('products')->body);

        $client->get('products/create');
        $client->post('products/save', $form());
        $used = (int) Database::pdo()->query('SELECT id FROM products')->fetchColumn();
        $page = $client->get('products/edit', ['id' => $used])->body;
        assert_not_contains('Delete this product', $page);
        assert_contains('it cannot be deleted', $page);
        $client->post('products/delete', ['product_id' => $used]);
        assert_same(1, (int) Database::pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn());
    },

    'a user who may only look sees the product without anything to save, and cannot post' => function () use ($form): void {
        Auth::login(TEST_ADMIN_ID);
        $id = (new ProductService())->save(0, ['name' => 'Hose', 'category_id' => '', 'base_unit' => 'piece', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
            'show_on_pos_grid' => true, 'allow_price_override' => false, 'units' => ['n1' => ['id' => '0', 'type' => 'Piece', 'retail' => '8']], 'cost' => '4.20', 'cost_unit' => 'n1'], TEST_ADMIN_ID);
        make_user('sara');
        Database::pdo()->exec("INSERT INTO role_permissions (role_id, perm_key) VALUES (2, 'product.view')");
        $client = login_as('sara', 'password123');
        $page = $client->get('products/edit', ['id' => $id])->body;
        assert_contains('value="Hose"', $page);
        assert_not_contains('data-then-set', $page, 'no Save button');
        assert_not_contains('data-remove', $page);
        assert_not_contains('id="cost-card"', $page, 'and no cost');
        assert_not_contains('4.20', $page);
        assert_not_contains('data-cost-base="0.0', $page);
        assert_same(403, $client->post('products/save', $form(['product_id' => (string) $id]))->status);
        assert_same(403, $client->post('products/delete', ['product_id' => $id])->status);
        assert_same('Hose', (new Product())->find($id)['name']);
    },

    'the list finds by barcode and prints what it shows' => function () use ($form): void {
        Auth::login(TEST_ADMIN_ID);
        $row = static fn (string $name, string $code): array => ['name' => $name, 'category_id' => '', 'base_unit' => 'piece', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
            'show_on_pos_grid' => true, 'allow_price_override' => false, 'units' => ['n1' => ['id' => '0', 'type' => 'Piece', 'retail' => '8', 'barcodes' => $code]]];
        $hose = (new ProductService())->save(0, $row('Silicone hose', 'HOSE-1'), TEST_ADMIN_ID);
        (new ProductService())->save(0, $row('Clay bowl', 'BOWL-1'), TEST_ADMIN_ID);
        (new StockService())->adjust($hose, (int) (new ProductUnit())->forProduct($hose)[0]['id'], '5', 'opening', '', null, TEST_ADMIN_ID);

        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $all = $client->get('products')->body;
        assert_contains('Silicone hose', $all);
        assert_contains('Clay bowl', $all);
        assert_contains('2 products', $all);
        assert_not_contains('name="price_min"', $all, 'three filters, not seven');
        $found = $client->get('products', ['q' => 'BOWL-1'])->body;
        assert_contains('Clay bowl', $found);
        assert_not_contains('Silicone hose', $found);
        assert_contains('1 product<', $found);
        assert_true((bool) preg_match('~products/print(&amp;|&)q=BOWL-1~', str_replace('%2F', '/', $found)), 'the print link carries the search');

        $printed = $client->get('products/print', ['stock' => 'in'])->body;
        assert_contains('Silicone hose', $printed);
        assert_not_contains('Clay bowl', $printed, 'out of stock, so not on a list of what is in stock');
    },
];
