<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\SaleService;

/** The product page saves everything at once: product, ways to sell it, prices, barcodes, cost, minimum and opening stock. */
$product = static fn (array $over = []): array => $over + [
    'name' => 'Coconut charcoal', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
    'show_on_pos_grid' => true, 'allow_price_override' => false, 'is_active' => true,
];
$row = static fn (string $type, array $over = []): array => $over + ['id' => '0', 'type' => $type, 'size' => '', 'size_unit' => '', 'retail' => '', 'wholesale' => '', 'barcodes' => ''];
$unitsOf = static fn (int $id): array => array_column((new ProductUnit())->forProduct($id), null, 'name');

/** Charcoal by weight: kg at $15 / $13 and a 20 kg box at $280 / $250, cost $200 per box, 10 boxes in stock. */
$charcoal = static function () use ($product, $row): int {
    return (new ProductService())->save(0, $product([
        'units' => [
            'n1' => $row('kg', ['retail' => '15', 'wholesale' => '13']),
            'n2' => $row('Box', ['size' => '20', 'size_unit' => 'kg', 'retail' => '280', 'wholesale' => '250', 'barcodes' => '6291041500213']),
        ],
        'main_unit' => 'n1', 'cost' => '200', 'cost_unit' => 'n2', 'min_qty' => '2', 'min_unit' => 'n2', 'opening_qty' => '10', 'opening_unit' => 'n2',
    ]), TEST_ADMIN_ID);
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a weighted product is created in one save: units, prices, barcode, cost, minimum and opening stock' => function () use ($charcoal, $unitsOf): void {
        $id = $charcoal();
        $p = (new Product())->find($id);
        $units = $unitsOf($id);
        assert_same(['kg', 'Box 20kg'], array_keys($units));
        assert_same(1000, (int) $units['kg']['factor']);
        assert_same(20000, (int) $units['Box 20kg']['factor'], '20 kg typed, 20,000 g stored');
        assert_same(1, (int) $units['kg']['allows_fraction'], 'a kg is sold in parts');
        assert_same(0, (int) $units['Box 20kg']['allows_fraction'], 'a box is not');
        assert_same('15.00', $units['kg']['retail_price']);
        assert_same('250.00', $units['Box 20kg']['wholesale_price']);
        assert_same(1, (int) $units['kg']['is_default_sale'], 'the till shows the main unit');
        assert_same(1, (int) $units['Box 20kg']['is_default_purchase'], 'purchases start with the largest unit');
        assert_same('0.010000', $p['cost_per_base'], '$200 per box of 20,000 g');
        assert_same(40000, (int) $p['min_stock_base'], '2 boxes');
        assert_same(200000, (int) $p['stock_base'], '10 boxes');
        assert_same('Box 20kg', (new Barcode())->findByBarcode('6291041500213')['unit_name']);
        assert_same('10 Box 20kg', \App\Services\Quantity::format((int) $p['stock_base'], array_values($units), 'g'));
    },

    'sizes: pieces are counted, weight and volume are typed in the unit or its thousand' => function () use ($product, $row, $unitsOf): void {
        $svc = new ProductService();
        $coal = $svc->save(0, $product(['name' => 'Cubes', 'base_unit' => 'piece', 'units' => [
            'n1' => $row('Piece', ['retail' => '0.25', 'size' => '99']), 'n2' => $row('Box', ['size' => '72', 'retail' => '12']), 'n3' => $row('Dozen'),
        ]]), TEST_ADMIN_ID);
        assert_same(['Piece' => 1, 'Dozen' => 12, 'Box of 72' => 72], array_map(static fn (array $u): int => (int) $u['factor'], $unitsOf($coal)));

        $tobacco = $svc->save(0, $product(['name' => 'Tobacco', 'units' => [
            'n1' => $row('Pack', ['size' => '250', 'size_unit' => 'g']), 'n2' => $row('Pack', ['size' => '0.05', 'size_unit' => 'kg']), 'n3' => $row('Bag', ['size' => '1,5', 'size_unit' => 'kg']),
        ]]), TEST_ADMIN_ID);
        assert_same(['Pack 50g' => 50, 'Pack 250g' => 250, 'Bag 1.5kg' => 1500], array_map(static fn (array $u): int => (int) $u['factor'], $unitsOf($tobacco)));

        $syrup = $svc->save(0, $product(['name' => 'Syrup', 'base_unit' => 'ml', 'units' => ['n1' => $row('L', ['retail' => '9']), 'n2' => $row('Bottle', ['size' => '0.5', 'size_unit' => 'L'])]]), TEST_ADMIN_ID);
        $units = $unitsOf($syrup);
        assert_same(500, (int) $units['Bottle 500ml']['factor']);
        assert_same(1, (int) $units['L']['allows_fraction']);

        foreach ([['Box', ''], ['Box', '0'], ['Box', 'abc'], ['Box', '-3'], ['Bucket', '5'], ['kg', '']] as [$type, $size]) {
            assert_throws(DomainException::class, fn () => $svc->save(0, $product(['name' => "Bad {$type} {$size}", 'base_unit' => 'piece', 'units' => ['n1' => $row($type, ['size' => $size])]]), TEST_ADMIN_ID));
        }
        assert_throws(DomainException::class, fn () => $svc->save(0, $product(['name' => 'No units', 'units' => []]), TEST_ADMIN_ID));
        assert_same(3, (int) Database::pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn(), 'a refused save leaves nothing behind');
    },

    'half a piece cannot be sold: only kg and L are sold in parts' => function () use ($product, $row): void {
        $svc = new ProductService();
        $id = $svc->save(0, $product(['name' => 'Hose', 'base_unit' => 'piece', 'units' => ['n1' => $row('Piece', ['retail' => '8'])], 'opening_qty' => '5', 'opening_unit' => 'n1']), TEST_ADMIN_ID);
        $piece = (new ProductUnit())->forProduct($id)[0];
        assert_same(0, (int) $piece['allows_fraction']);
        $svc->updateUnit((int) $piece['id'], 'Piece', '1', true, true);   // the old form could tick "sold in fractions" on a piece
        assert_same(0, (int) (new ProductUnit())->find((int) $piece['id'])['allows_fraction']);

        $registerId = (new Register())->create('Register 01');
        $sessionId = (new CashService())->open($registerId, TEST_ADMIN_ID, '100', '0');
        assert_throws(DomainException::class, fn () => (new SaleService())->complete([
            'register_id' => $registerId, 'session_id' => $sessionId, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $id, 'unit_id' => (int) $piece['id'], 'qty' => '0.5']], 'invoice_discount' => '',
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '4']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
        ]));
        assert_same(5, (int) (new Product())->find($id)['stock_base']);
    },

    'one save changes the name, a price, a barcode and adds a unit together' => function () use ($charcoal, $product, $row, $unitsOf): void {
        $id = $charcoal();
        $units = $unitsOf($id);
        $kg = (int) $units['kg']['id'];
        $box = (int) $units['Box 20kg']['id'];
        $logged = (int) Database::pdo()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
        (new ProductService())->save($id, $product([
            'name' => 'Coconut charcoal premium',
            'units' => [
                "u{$kg}" => $row('kg', ['id' => (string) $kg, 'retail' => '16', 'wholesale' => '13', 'barcodes' => 'KG-001 KG-002']),
                "u{$box}" => $row('Box', ['id' => (string) $box, 'size' => '20', 'size_unit' => 'kg', 'retail' => '280', 'wholesale' => '250', 'barcodes' => '6291041500213']),
                'n1' => $row('Bag', ['size' => '5', 'size_unit' => 'kg', 'retail' => '72']),
            ],
            'main_unit' => "u{$kg}", 'min_qty' => '2', 'min_unit' => "u{$box}",
        ]), TEST_ADMIN_ID);
        $p = (new Product())->find($id);
        $units = $unitsOf($id);
        assert_same('Coconut charcoal premium', $p['name']);
        assert_same('16.00', $units['kg']['retail_price']);
        assert_same(['kg', 'Bag 5kg', 'Box 20kg'], array_keys($units));
        assert_same('72.00', $units['Bag 5kg']['retail_price']);
        assert_same(['KG-001', 'KG-002', '6291041500213'], array_column((new Barcode())->forProduct($id), 'barcode'), 'listed by unit, smallest first');
        assert_same('0.010000', $p['cost_per_base'], 'an empty cost field keeps the cost');
        assert_same(200000, (int) $p['stock_base'], 'and the stock');
        assert_same(40000, (int) $p['min_stock_base']);

        $noise = (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('product.unit_updated', 'product.cost_changed') AND id > {$logged}")->fetchColumn();
        assert_same(0, $noise, 'what did not change is not logged as changed');
    },

    'a barcode moves from one unit to another in one save; one used by another product is refused' => function () use ($charcoal, $product, $row, $unitsOf): void {
        $id = $charcoal();
        $units = $unitsOf($id);
        $kg = (int) $units['kg']['id'];
        $box = (int) $units['Box 20kg']['id'];
        $svc = new ProductService();
        $page = static fn (string $kgCodes, string $boxCodes): array => [
            "u{$kg}" => $row('kg', ['id' => (string) $kg, 'retail' => '15', 'barcodes' => $kgCodes]),
            "u{$box}" => $row('Box', ['id' => (string) $box, 'size' => '20', 'size_unit' => 'kg', 'retail' => '280', 'barcodes' => $boxCodes]),
        ];
        $svc->save($id, $product(['units' => $page('6291041500213', '')]), TEST_ADMIN_ID);
        assert_same('kg', (new Barcode())->findByBarcode('6291041500213')['unit_name']);

        $other = $svc->save(0, $product(['name' => 'Other', 'base_unit' => 'piece', 'units' => ['n1' => $row('Piece', ['barcodes' => 'OTHER-1'])]]), TEST_ADMIN_ID);
        $e = assert_throws(DomainException::class, fn () => $svc->save($id, $product(['name' => 'Renamed', 'units' => $page('6291041500213, OTHER-1', '')]), TEST_ADMIN_ID));
        assert_contains('already used by Other', $e->getMessage());
        assert_same('Coconut charcoal', (new Product())->find($id)['name'], 'the whole save was refused, the name too');
        assert_true($other > 0);
    },

    'with stock history a size is locked, and a unit that was sold stays' => function () use ($charcoal, $product, $row, $unitsOf): void {
        $id = $charcoal();   // the opening stock is history
        $units = $unitsOf($id);
        $kg = (int) $units['kg']['id'];
        $box = (int) $units['Box 20kg']['id'];
        $svc = new ProductService();
        $e = assert_throws(DomainException::class, fn () => $svc->save($id, $product(['units' => [
            "u{$kg}" => $row('kg', ['id' => (string) $kg, 'retail' => '15']), "u{$box}" => $row('Box', ['id' => (string) $box, 'size' => '25', 'size_unit' => 'kg', 'retail' => '280']),
        ]]), TEST_ADMIN_ID));
        assert_contains('cannot change its size', $e->getMessage());
        assert_contains('already has stock history', assert_throws(DomainException::class, fn () => $svc->save($id, $product(['units' => [
            "u{$kg}" => $row('kg', ['id' => (string) $kg, 'retail' => '15']), "u{$box}" => $row('Box', ['id' => (string) $box, 'size' => '20', 'size_unit' => 'kg']),
        ], 'opening_qty' => '3', 'opening_unit' => "u{$box}"]), TEST_ADMIN_ID))->getMessage());

        $registerId = (new Register())->create('Register 01');
        $sessionId = (new CashService())->open($registerId, TEST_ADMIN_ID, '100', '0');
        (new SaleService())->complete([
            'register_id' => $registerId, 'session_id' => $sessionId, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $id, 'unit_id' => $box, 'qty' => '1']], 'invoice_discount' => '',
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '280']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
        ]);
        $e = assert_throws(DomainException::class, fn () => $svc->save($id, $product(['units' => ["u{$kg}" => $row('kg', ['id' => (string) $kg, 'retail' => '15'])]]), TEST_ADMIN_ID));
        assert_contains('Box 20kg was sold or purchased before', $e->getMessage());
        // Emptying its prices is how it stops being sold.
        $svc->save($id, $product(['units' => ["u{$kg}" => $row('kg', ['id' => (string) $kg, 'retail' => '15']), "u{$box}" => $row('Box', ['id' => (string) $box, 'size' => '20', 'size_unit' => 'kg'])]]), TEST_ADMIN_ID);
        assert_same(null, $unitsOf($id)['Box 20kg']['retail_price']);
    },

    'a unit named by hand before the list existed is left exactly as it is' => function () use ($product, $row, $unitsOf): void {
        $id = make_product('Old tobacco', 'g');
        $loose = (new ProductUnit())->create($id, 'Loose 100g', 100, true, false);   // as the first form stored it
        (new ProductUnit())->setPrices($loose, '3.80', null);
        (new ProductService())->save($id, $product(['name' => 'Old tobacco', 'units' => [
            "u{$loose}" => $row(ProductService::KEEP_TYPE, ['id' => (string) $loose, 'retail' => '4.00']), 'n1' => $row('kg', ['retail' => '38']),
        ]]), TEST_ADMIN_ID);
        $units = $unitsOf($id);
        assert_same(100, (int) $units['Loose 100g']['factor'], 'not 1 g, as the old page would have made it');
        assert_same(1, (int) $units['Loose 100g']['allows_fraction']);
        assert_same('4.00', $units['Loose 100g']['retail_price'], 'its price can still change');
        assert_same(1000, (int) $units['kg']['factor']);
    },

    'the same name is asked about, not refused' => function () use ($charcoal, $product, $row): void {
        $charcoal();
        $svc = new ProductService();
        $again = $product(['name' => ' coconut CHARCOAL ', 'units' => ['n1' => $row('kg', ['retail' => '18'])]]);
        $e = assert_throws(DomainException::class, fn () => $svc->save(0, $again, TEST_ADMIN_ID));
        assert_same(ProductService::SAME_NAME, $e->getCode());
        assert_contains('code P-000001', $e->getMessage());
        assert_same(1, (int) Database::pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn());
        $second = $svc->save(0, $again + ['allow_same_name' => true], TEST_ADMIN_ID);
        assert_same('coconut CHARCOAL', (new Product())->find($second)['name']);
        $svc->save($second, $product(['name' => 'coconut CHARCOAL', 'description' => 'the small bag', 'units' => ['u' => $row('kg', ['id' => (string) (new ProductUnit())->forProduct($second)[0]['id'], 'retail' => '18'])]]), TEST_ADMIN_ID);
        assert_same('the small bag', (new Product())->find($second)['description'], 'saving it again under its own name asks nothing');
    },

    'a product that was never used is deleted; one with history is only deactivated' => function () use ($charcoal, $product, $row): void {
        $svc = new ProductService();
        $mistake = $svc->save(0, $product(['name' => 'Typed by mistake', 'base_unit' => 'piece', 'units' => ['n1' => $row('Piece', ['retail' => '5', 'barcodes' => 'MISTAKE-1'])]]), TEST_ADMIN_ID);
        assert_false($svc->isUsed($mistake));
        $svc->delete($mistake);
        assert_same(null, (new Product())->find($mistake));
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM product_units')->fetchColumn());
        assert_same(null, (new Barcode())->findByBarcode('MISTAKE-1'), 'its barcode is free again');
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'product.deleted'")->fetchColumn());

        $used = $charcoal();   // has an opening stock movement
        assert_true($svc->isUsed($used));
        assert_contains('cannot be deleted', assert_throws(DomainException::class, fn () => $svc->delete($used))->getMessage());
        assert_true((new Product())->find($used) !== null);
    },
];
