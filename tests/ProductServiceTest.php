<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\Product;
use App\Services\ProductService;

$form = static fn (array $override = []): array => array_merge([
    'name' => 'Charcoal', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '',
    'description' => '', 'target_margin_pct' => '', 'show_on_pos_grid' => true, 'allow_price_override' => false,
], $override);

$noFilters = ['q' => '', 'category_id' => 0, 'stock' => '', 'unit' => '', 'price_min' => null, 'price_max' => null, 'inactive' => false];

/** A unit row without the service (Task 5 adds the real API). */
$rawUnit = static function (int $productId, string $name, int $factor, bool $defaultSale, ?string $retail = null): int {
    Database::pdo()->prepare(
        'INSERT INTO product_units (product_id, name, factor, is_default_sale, retail_price) VALUES (:p, :n, :f, :d, :r)'
    )->execute(['p' => $productId, 'n' => $name, 'f' => $factor, 'd' => (int) $defaultSale, 'r' => $retail]);

    return (int) Database::pdo()->lastInsertId();
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'products get gap-free auto codes and keep an arabic name' => function () use ($form): void {
        $a = (new ProductService())->create($form(['name' => 'فحم']));
        $b = (new ProductService())->create($form(['name' => 'Hose']));
        assert_same('P-000001', (new Product())->find($a)['internal_code']);
        assert_same('P-000002', (new Product())->find($b)['internal_code']);
        assert_same('فحم', (new Product())->find($a)['name']);
        assert_same('g', (new Product())->find($a)['base_unit']);
        assert_same(0, (int) (new Product())->find($a)['stock_base']);
    },

    'a typed code is kept, must be safe characters, and is unique in any letter case' => function () use ($form): void {
        $id = (new ProductService())->create($form(['internal_code' => 'CHAR-01']));
        assert_same('CHAR-01', (new Product())->find($id)['internal_code']);
        $e = assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['name' => 'Other', 'internal_code' => 'char-01'])));
        assert_contains('already used', $e->getMessage());
        foreach (['x', 'has space', 'bad/char', str_repeat('a', 31)] as $bad) {
            assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['internal_code' => $bad])));
        }
    },

    'an auto code skips a number that was typed by hand' => function () use ($form): void {
        (new ProductService())->create($form(['name' => 'Manual', 'internal_code' => 'P-000001']));
        $id = (new ProductService())->create($form(['name' => 'Auto']));
        assert_same('P-000002', (new Product())->find($id)['internal_code']);
    },

    'category, base unit, name and target margin are validated' => function () use ($form): void {
        $cat = make_category('Charcoal');
        $id = (new ProductService())->create($form(['category_id' => (string) $cat, 'target_margin_pct' => '40']));
        $row = (new Product())->find($id);
        assert_same('Charcoal', $row['category_name']);
        assert_same('40.00', $row['target_margin_pct']);
        assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['category_id' => '999'])));
        assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['base_unit' => 'kg'])));
        assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['name' => '  '])));
        assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['target_margin_pct' => 'abc'])));
        assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['target_margin_pct' => '-5'])));
    },

    'update changes fields, and the base unit is locked once the product has units' => function () use ($form, $rawUnit): void {
        $id = (new ProductService())->create($form());
        (new ProductService())->update($id, $form(['name' => 'Charcoal (Coco)', 'base_unit' => 'piece', 'is_active' => false, 'show_on_pos_grid' => false]));
        $row = (new Product())->find($id);
        assert_same('Charcoal (Coco)', $row['name']);
        assert_same('piece', $row['base_unit'], 'base unit may change while there are no units');
        assert_same(0, (int) $row['is_active']);
        assert_same(0, (int) $row['show_on_pos_grid']);

        $rawUnit($id, 'Dozen', 12, true);
        $e = assert_throws(DomainException::class, fn () => (new ProductService())->update($id, $form(['base_unit' => 'g', 'is_active' => true])));
        assert_contains('units', $e->getMessage());
        (new ProductService())->update($id, $form(['base_unit' => 'piece', 'is_active' => true]));
    },

    'cost is entered per unit and stored per base unit: $200 per Box of 20,000 g = $0.010000' => function () use ($form): void {
        $id = (new ProductService())->create($form());
        assert_same('0.010000', (new ProductService())->setCost($id, '200', 20000));
        assert_same('0.010000', (new Product())->find($id)['cost_per_base']);
        assert_same('3.500000', (new ProductService())->setCost($id, '3.50'));
        $row = Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'product.cost_changed' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same(['old' => '0.010000', 'new' => '3.500000', 'entered' => '3.50', 'factor' => 1], json_decode((string) $row['details'], true));
        assert_throws(DomainException::class, fn () => (new ProductService())->setCost($id, '-1'));
        assert_throws(DomainException::class, fn () => (new ProductService())->setCost($id, 'abc'));
    },

    'search finds by name, by internal code and by an exact barcode' => function () use ($form, $noFilters, $rawUnit): void {
        $charcoal = (new ProductService())->create($form(['name' => 'فحم جوز الهند', 'internal_code' => 'CHAR-01']));
        $hose = (new ProductService())->create($form(['name' => 'Hose', 'base_unit' => 'piece']));
        $unit = $rawUnit($hose, 'Piece', 1, true, '3.50');
        Database::pdo()->exec("INSERT INTO barcodes (product_unit_id, barcode) VALUES ({$unit}, '6291041500213')");
        $p = new Product();

        assert_same([$charcoal], array_map('intval', array_column($p->search(['q' => 'جوز'] + $noFilters, 1)['rows'], 'id')));
        assert_same([$charcoal], array_map('intval', array_column($p->search(['q' => 'char-01'] + $noFilters, 1)['rows'], 'id')));
        assert_same([$hose], array_map('intval', array_column($p->search(['q' => '6291041500213'] + $noFilters, 1)['rows'], 'id')));
        assert_same([], $p->search(['q' => '62910415'] + $noFilters, 1)['rows'], 'a partial barcode is not a match');
        assert_same(2, $p->search($noFilters, 1)['total']);
    },

    'search filters by category, stock status, unit name, price range and inactive' => function () use ($form, $noFilters, $rawUnit): void {
        $cat = make_category('Charcoal');
        $a = (new ProductService())->create($form(['name' => 'A', 'category_id' => (string) $cat]));
        $b = (new ProductService())->create($form(['name' => 'B']));
        $c = (new ProductService())->create($form(['name' => 'C']));
        $rawUnit($a, 'kg', 1000, true, '15.00');
        $rawUnit($b, 'Piece', 1, true, '3.50');
        // Stock is set directly here only because StockService does not exist yet (Phase 3).
        Database::pdo()->exec("UPDATE products SET stock_base = 5000, min_stock_base = 10000 WHERE id = {$a}");
        Database::pdo()->exec("UPDATE products SET stock_base = 3 WHERE id = {$b}");
        Database::pdo()->exec("UPDATE products SET is_active = 0 WHERE id = {$c}");
        $p = new Product();
        $ids = static fn (array $result): array => array_map('intval', array_column($result['rows'], 'id'));

        assert_same([$a], $ids($p->search(['category_id' => $cat] + $noFilters, 1)));
        assert_same([$a, $b], $ids($p->search(['stock' => 'in'] + $noFilters, 1)));
        assert_same([$a], $ids($p->search(['stock' => 'low'] + $noFilters, 1)));
        assert_same([], $ids($p->search(['stock' => 'out'] + $noFilters, 1)), 'C is inactive, so hidden');
        assert_same([$c], $ids($p->search(['stock' => 'out', 'inactive' => true] + $noFilters, 1)));
        assert_same([$a], $ids($p->search(['unit' => 'kg'] + $noFilters, 1)));
        assert_same([$b], $ids($p->search(['price_max' => '5.00'] + $noFilters, 1)));
        assert_same([$a], $ids($p->search(['price_min' => '10.00', 'price_max' => '20.00'] + $noFilters, 1)));
        assert_same('kg', $p->search(['category_id' => $cat] + $noFilters, 1)['rows'][0]['sale_unit_name']);
    },

    'search paginates 25 per page and forPrint lists every active product' => function () use ($form, $noFilters): void {
        for ($i = 1; $i <= 30; $i++) {
            (new ProductService())->create($form(['name' => sprintf('Item %02d', $i)]));
        }
        $page2 = (new Product())->search($noFilters, 2);
        assert_same(30, $page2['total']);
        assert_same(2, $page2['pages']);
        assert_same(5, count($page2['rows']));
        assert_same(30, count((new Product())->forPrint()));
    },

    'product changes are audited' => function () use ($form): void {
        $id = (new ProductService())->create($form());
        $row = Database::pdo()->query("SELECT entity_id, details FROM audit_log WHERE action = 'product.created' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same($id, (int) $row['entity_id']);
        assert_contains('P-000001', (string) $row['details']);
    },
];
