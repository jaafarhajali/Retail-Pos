<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Models\ProductUnit;
use App\Services\ProductService;

/** Units are picked from a fixed list of types; the name is composed from the type and the size. */
return [
    '__before' => 'test_db_reset',

    'unit names are composed from the type and the number of base units' => function (): void {
        $svc = new ProductService();
        $units = new ProductUnit();
        $coal = make_product('Charcoal', 'piece');
        assert_same('Box of 6', $units->find($svc->addUnit($coal, 'Box', '6', false, false))['name']);
        $piece = $units->find($svc->addUnit($coal, 'piece', '99', false, false));   // plain measure: factor forced, name canonical
        assert_same('Piece', $piece['name']);
        assert_same(1, (int) $piece['factor']);
        assert_same('Dozen', $units->find($svc->addUnit($coal, 'Dozen', '', false, false))['name']);
        $tobacco = make_product('Tobacco', 'g');
        assert_same('Pack 250g', $units->find($svc->addUnit($tobacco, 'Pack', '250', false, false))['name']);
        assert_same('Box 1kg', $units->find($svc->addUnit($tobacco, 'Box', '1000', false, false))['name']);
        assert_same('kg', $units->find($svc->addUnit($tobacco, 'KG', '', true, false))['name']);
        $syrup = make_product('Syrup', 'ml');
        assert_same('Bottle 500ml', $units->find($svc->addUnit($syrup, 'Bottle', '500', false, false))['name']);
        assert_same('Can', $units->find($svc->addUnit($syrup, 'Can', '1', false, false))['name']);
    },

    'a type must come from the list, fit the base unit, and a size is one unit only once' => function (): void {
        $svc = new ProductService();
        $coal = make_product('Charcoal', 'piece');
        assert_throws(DomainException::class, fn () => $svc->addUnit($coal, 'Bucket', '5', false, false));
        assert_throws(DomainException::class, fn () => $svc->addUnit($coal, 'kg', '1000', false, false));
        assert_throws(DomainException::class, fn () => $svc->addUnit($coal, 'Box', '', false, false));
        $svc->addUnit($coal, 'Box', '6', false, false);
        assert_throws(DomainException::class, fn () => $svc->addUnit($coal, 'box', '6', false, false));
        $svc->addUnit($coal, 'Box', '12', false, false);   // a different size is a different unit
        assert_same(2, count(array_filter((new ProductUnit())->forProduct($coal), fn (array $u): bool => str_starts_with($u['name'], 'Box'))));
    },

    'editing a unit recomposes its name' => function (): void {
        $svc = new ProductService();
        $coal = make_product('Charcoal', 'piece');
        $id = $svc->addUnit($coal, 'Box', '6', false, false);
        $svc->updateUnit($id, 'Carton', '6', false, false);
        assert_same('Carton of 6', (new ProductUnit())->find($id)['name']);
    },

    'unitType finds the dropdown value for a stored name' => function (): void {
        assert_same('Pack', ProductService::unitType('Pack 250g'));
        assert_same('Box', ProductService::unitType('Box of 6'));
        assert_same('kg', ProductService::unitType('kg'));
        assert_same('Piece', ProductService::unitType('Piece'));
    },
];