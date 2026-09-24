<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\ProductService;
use App\Services\Quantity;

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    // The phase's "done when": charcoal as g + kg + Box with its own prices.
    'charcoal is defined as g with kg and Box units that have their own prices' => function (): void {
        $svc = new ProductService();
        $id = make_product('فحم', 'g');
        $kg = $svc->addUnit($id, 'kg', '1000', true, false);
        $box = $svc->addUnit($id, 'Box', '20000', false, true);
        $svc->setPrices($kg, '15.00', '13.00');
        $svc->setPrices($box, '280', '250');
        $svc->setCost($id, '200', 20000);

        $units = (new ProductUnit())->forProduct($id);
        assert_same(['kg', 'Box'], array_column($units, 'name'));
        assert_same('15.00', $units[0]['retail_price']);
        assert_same('280.00', $units[1]['retail_price']);
        assert_same('250.00', $units[1]['wholesale_price']);
        assert_same(1, (int) $units[0]['is_default_sale'], 'the first unit is the default sale unit');
        assert_same(1, (int) $units[0]['is_default_purchase']);
        assert_same('0.010000', (new Product())->find($id)['cost_per_base']);

        Database::pdo()->exec("UPDATE products SET stock_base = 197500 WHERE id = {$id}");   // Phase 3 owns stock; direct only in tests
        assert_same('9 Box + 17.5 kg', Quantity::format(197500, $units, 'g'));
    },

    'unit names are unique per product in any case and factors are whole numbers of at least 1' => function (): void {
        $svc = new ProductService();
        $id = make_product('Charcoal', 'g');
        $svc->addUnit($id, 'kg', '1000', true, false);
        assert_throws(DomainException::class, fn () => $svc->addUnit($id, 'KG', '1000', true, false));
        foreach (['0', 'abc', '2.5', '', '9999999999'] as $bad) {
            assert_throws(DomainException::class, fn () => $svc->addUnit($id, 'Box', $bad, false, false));
        }
        assert_throws(DomainException::class, fn () => $svc->addUnit($id, '', '10', false, false));
        assert_throws(DomainException::class, fn () => $svc->addUnit($id, str_repeat('x', 31), '10', false, false));
        $other = make_product('Other', 'g');
        $svc->addUnit($other, 'kg', '1000', true, false);   // same name on another product is fine
        assert_same(1, (new ProductUnit())->count($other));
    },

    'default sale and purchase units: exactly one each, movable, re-assigned when the default is deleted' => function (): void {
        $svc = new ProductService();
        $id = make_product('Tobacco');
        $piece = $svc->addUnit($id, 'Piece', '1', false, false);
        $carton = $svc->addUnit($id, 'Carton', '24', false, true);
        $svc->setDefaultUnit($carton, 'purchase');
        $units = array_column((new ProductUnit())->forProduct($id), null, 'name');
        assert_same(1, (int) $units['Piece']['is_default_sale']);
        assert_same(0, (int) $units['Piece']['is_default_purchase']);
        assert_same(1, (int) $units['Carton']['is_default_purchase']);
        assert_throws(DomainException::class, fn () => $svc->setDefaultUnit($carton, 'nonsense'));

        $svc->deleteUnit($piece);
        $left = (new ProductUnit())->forProduct($id);
        assert_same(1, count($left));
        assert_same(1, (int) $left[0]['is_default_sale'], 'the remaining unit became the default sale unit');
    },

    'updateUnit renames and changes the factor and flags' => function (): void {
        $svc = new ProductService();
        $id = make_product('Charcoal', 'g');
        $unit = $svc->addUnit($id, 'Box', '20000', false, false);
        $svc->updateUnit($unit, 'Big box', '25000', false, true);
        $row = (new ProductUnit())->find($unit);
        assert_same('Big box', $row['name']);
        assert_same(25000, (int) $row['factor']);
        assert_same(1, (int) $row['is_display']);
        assert_same('g', $row['base_unit']);
    },

    // Review focus 4
    'prices: separators are accepted, empty means "not sold at that level", changes are audited' => function (): void {
        $svc = new ProductService();
        $id = make_product('Charcoal', 'g');
        $unit = $svc->addUnit($id, 'Box', '20000', false, true);
        $svc->setPrices($unit, '1,250.00', '');
        $row = (new ProductUnit())->find($unit);
        assert_same('1250.00', $row['retail_price']);
        assert_same(null, $row['wholesale_price']);

        $svc->setPrices($unit, '15,5', '14');
        $audit = Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'product.price_changed' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same(
            ['unit' => 'Box', 'retail' => ['old' => '1250.00', 'new' => '15.50'], 'wholesale' => ['old' => null, 'new' => '14.00']],
            json_decode((string) $audit['details'], true)
        );
        assert_throws(DomainException::class, fn () => $svc->setPrices($unit, '-5', ''));
        assert_throws(DomainException::class, fn () => $svc->setPrices($unit, 'abc', ''));
    },

    // Review focus 2
    'barcodes: several per unit, unique across the whole catalog, scanner line endings stripped' => function (): void {
        $svc = new ProductService();
        $id = make_product('Al Fakher Apple');
        $piece = $svc->addUnit($id, 'Piece', '1', false, false);
        $svc->addBarcode($piece, "6291041500213\r\n");
        $svc->addBarcode($piece, ' 6291041500220 ');
        assert_same(['6291041500213', '6291041500220'], array_column((new Barcode())->forProduct($id), 'barcode'));

        $other = make_product('Other');
        $otherUnit = $svc->addUnit($other, 'Piece', '1', false, false);
        $e = assert_throws(DomainException::class, fn () => $svc->addBarcode($otherUnit, '6291041500213'));
        assert_contains('Al Fakher Apple', $e->getMessage());
        foreach (['12', 'has space', 'bad/char', str_repeat('1', 65)] as $bad) {
            assert_throws(DomainException::class, fn () => $svc->addBarcode($piece, $bad));
        }

        $found = (new Barcode())->findByBarcode('6291041500213');
        assert_same($id, (int) $found['product_id']);
        assert_same('Piece', $found['unit_name']);
        assert_same(null, (new Barcode())->findByBarcode('0000000000000'));
    },

    'removing a barcode, and deleting a unit removes its barcodes' => function (): void {
        $svc = new ProductService();
        $id = make_product('Hose');
        $piece = $svc->addUnit($id, 'Piece', '1', false, false);
        $a = $svc->addBarcode($piece, '111111');
        $svc->addBarcode($piece, '222222');
        $svc->removeBarcode($a);
        assert_same(['222222'], array_column((new Barcode())->forProduct($id), 'barcode'));
        $svc->deleteUnit($piece);
        assert_same([], (new Barcode())->forProduct($id));
        assert_same(null, (new Barcode())->findByBarcode('222222'));
    },

    // Review focus 1, applied to minimum stock
    'minimum stock is entered in a unit and stored in base units' => function (): void {
        $svc = new ProductService();
        $id = make_product('Charcoal', 'g');
        $box = $svc->addUnit($id, 'Box', '20000', false, true);
        $svc->setMinStock($id, '2', $box);
        assert_same(40000, (int) (new Product())->find($id)['min_stock_base']);
        $svc->setMinStock($id, '500', 0);   // 0 = base unit
        assert_same(500, (int) (new Product())->find($id)['min_stock_base']);
        $svc->setMinStock($id, '', $box);
        assert_same(null, (new Product())->find($id)['min_stock_base']);
        assert_throws(DomainException::class, fn () => $svc->setMinStock($id, '2.5', $box));
        $foreignUnit = $svc->addUnit(make_product('Other', 'g'), 'kg', '1000', true, false);
        assert_throws(DomainException::class, fn () => $svc->setMinStock($id, '1', $foreignUnit));
    },

    'forProducts groups units by product for list pages' => function (): void {
        $svc = new ProductService();
        $a = make_product('A', 'g');
        $b = make_product('B');
        $svc->addUnit($a, 'kg', '1000', true, false);
        $svc->addUnit($a, 'Box', '20000', false, true);
        $svc->addUnit($b, 'Piece', '1', false, false);
        $grouped = (new ProductUnit())->forProducts([$a, $b, 999]);
        assert_same(['kg', 'Box'], array_column($grouped[$a], 'name'));
        assert_same(['Piece'], array_column($grouped[$b], 'name'));
        assert_false(isset($grouped[999]));
        assert_same([], (new ProductUnit())->forProducts([]));
    },
];
