<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Services\ProductService;
use App\Services\StockService;

/**
 * 2026-11-09 (Aya): bin/product-sheet.php builds the Excel the owner fills in with his products.
 * 2026-11-10: one tab only, the unit names and categories the system knows (as dropdowns), and no
 * existing product listed. It must be a real .xlsx (a zip of well-formed XML parts).
 */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'one sheet: the units and categories of the system are there to pick from, the existing products are not' => function (): void {
        $svc = new ProductService();
        $cat = (new \App\Models\Category())->create('Tobacco', '#6c757d', 1);
        $p = make_product('Nakhla Double Apple', 'g', $cat);
        $kg = $svc->addUnit($p, 'kg', '1000', false, true);
        $svc->setPrices($kg, '30.00', '28.00');
        $box = $svc->addUnit($p, 'Box', '10000', false, false);
        $svc->setPrices($box, '280.00', '');
        (new StockService())->adjust($p, $kg, '4', 'opening', '', '1', TEST_ADMIN_ID);
        $out = dirname(__DIR__) . '/storage/exports/test-products-sheet.xlsx';
        @unlink($out);
        exec(sprintf('"%s" "%s" "%s" 2>&1', PHP_BINARY, dirname(__DIR__) . '/bin/product-sheet.php', $out), $lines, $rc);   // RETAIL_POS_ENV=test is inherited
        assert_same(0, $rc, implode("\n", $lines));
        assert_true(is_file($out), 'the file exists');

        $zip = new ZipArchive();
        assert_same(true, $zip->open($out), 'it is a zip');
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $xml = $zip->getFromName($part);
            assert_true($xml !== false, $part . ' is in the zip');
            assert_true(simplexml_load_string($xml) !== false, $part . ' is well-formed XML');
        }
        assert_true($zip->getFromName('xl/worksheets/sheet2.xml') === false, 'there is no second sheet');
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        assert_same(1, substr_count($workbook, '<sheet '), 'one tab');
        assert_contains('name="Products"', $workbook);

        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        assert_contains('Units the system has', $sheet);
        foreach (array_keys(ProductService::UNIT_TYPES) as $unit) {
            assert_contains('>' . $unit . '<', $sheet, 'every unit type the system accepts is listed: ' . $unit);
        }
        assert_contains('you write it: Box of 6 = 6, Box 10kg = 10000', $sheet, 'and how a sized unit is written');
        assert_contains('>Tobacco<', $sheet, 'the categories too');
        assert_not_contains('Nakhla Double Apple', $sheet, 'no existing product on the sheet');
        assert_not_contains('28.00', $sheet, 'nor its prices');
        assert_contains('<dataValidation type="list"', $sheet, 'dropdowns for category, unit name, base unit and yes/no');
        assert_contains('&quot;piece,g,ml&quot;', $sheet);
        // the layout is fixed: title, blank, "How to fill" + 7 steps, blank, units heading + header + 11 unit types, blank,
        // "Your products", header, 2 examples, then the 200 yellow lines (rows 30-229). Excel refused "B1:B0" on 2026-11-10.
        assert_contains('sqref="B30:B229"', $sheet, 'the dropdowns cover exactly the yellow lines');
        assert_contains('sqref="M30:N229"', $sheet);
        assert_contains('<formula1>$A$14:$A$24</formula1>', $sheet, 'the unit dropdown points at the 11 listed unit types');
        assert_contains('<formula1>$E$14:$E$14</formula1>', $sheet, 'the category dropdown points at the one listed category');
        assert_contains('<selection activeCell="A30"', $sheet, 'the cursor starts on the first yellow line');
        assert_contains('Stock now in Shop', $sheet);
        assert_contains('Stock now in Warehouse', $sheet);
        assert_contains('Other units it is also sold in', $sheet);
        $zip->close();
        unlink($out);
    },
];
