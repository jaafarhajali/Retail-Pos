<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Services\ProductService;
use App\Services\StockService;

/**
 * 2026-11-09 (Aya): bin/product-sheet.php builds the Excel the owner fills in with his products.
 * 2026-11-10: one tab only, nothing from the catalogue on it, the eleven ways to sell as the one dropdown,
 * and no price, cost or stock columns (he sets those in the system later). A real .xlsx: a zip of well-formed XML parts.
 */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'one sheet, products only: every unit of the system to pick from; no catalogue, no price and no stock columns' => function (): void {
        $svc = new ProductService();
        $cat = (new \App\Models\Category())->create('Tobacco', '#6c757d', 1);
        $p = make_product('Nakhla Double Apple', 'g', $cat);
        $kg = $svc->addUnit($p, 'kg', '1000', false, true);
        $svc->setPrices($kg, '30.00', '28.00');
        $svc->addUnit($p, 'Box', '10000', false, false);          // named "Box 10kg"
        $p2 = make_product('Coal Tabs', 'piece', $cat);
        $svc->addUnit($p2, 'Pack', '10', false, true);            // named "Pack of 10"
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
        assert_contains('Units in the system', $sheet);
        foreach (['Piece' => 'one piece', 'Dozen' => '12 pieces', 'g' => 'one gram', 'kg' => '1 kg', 'ml' => 'one millilitre', 'L' => '1 litre',
                  'Box 10kg' => '10 kg', 'Pack of 10' => '10 pieces'] as $unit => $what) {
            assert_contains('>' . $unit . '</t></is></c><c r="B', $sheet, 'the unit is listed: ' . $unit);
            assert_contains('>' . $what . '<', $sheet, 'with its meaning: ' . $what);
        }
        assert_not_contains('Tobacco', $sheet, 'no category of the system on the sheet');
        assert_not_contains('Nakhla Double Apple', $sheet, 'no existing product on the sheet');
        assert_not_contains('Coal Tabs', $sheet);
        assert_not_contains('28.00', $sheet, 'nor its prices');
        assert_same(1, substr_count($sheet, '<dataValidation '), 'one dropdown, on the three unit columns');
        // the layout is fixed: title, blank, 5 steps, blank, units heading + header + 8 units (6 measures + Box 10kg + Pack of 10),
        // blank, "My products", header, 2 examples, then the 200 yellow lines (rows 24-223). Excel refused "B1:B0" on 2026-11-10.
        assert_contains('sqref="C24:E223"', $sheet, 'the dropdown covers exactly the yellow unit cells');
        assert_contains('<formula1>$A$11:$A$18</formula1>', $sheet, 'and points at the 8 listed units');
        assert_contains('<selection activeCell="A24"', $sheet, 'the cursor starts on the first yellow line');
        assert_contains('>Unit *<', $sheet);
        assert_contains('Unit 2 (if sold in another unit too)', $sheet);
        assert_contains('Barcode (if you have it)', $sheet);
        foreach (['Retail price', 'Wholesale price', 'Cost $', 'How many', 'Warn me', 'what is inside?'] as $gone) {
            assert_not_contains($gone, $sheet, 'no ' . $gone . ' column: he sets prices and quantities in the system later');
        }
        $zip->close();
        unlink($out);
    },
];
