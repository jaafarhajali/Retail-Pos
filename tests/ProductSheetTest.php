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
        assert_contains('How you can sell something (column C)', $sheet);
        foreach (array_keys(ProductService::UNIT_TYPES) as $unit) {
            assert_contains('>' . $unit . '<', $sheet, 'every way the system can sell is listed: ' . $unit);
        }
        assert_contains('write what is inside it in column D', $sheet, 'and what to do for a Box, Pack …');
        assert_not_contains('Tobacco', $sheet, 'no category of the system on the sheet');
        assert_not_contains('Nakhla Double Apple', $sheet, 'no existing product on the sheet');
        assert_not_contains('28.00', $sheet, 'nor its prices');
        assert_same(1, substr_count($sheet, '<dataValidation '), 'one dropdown only: how you sell it');
        // the layout is fixed: title, blank, 5 steps, blank, units heading + header + 11 ways to sell, blank,
        // "My products", header, 2 examples, then the 200 yellow lines (rows 27-226). Excel refused "B1:B0" on 2026-11-10.
        assert_contains('sqref="C27:C226"', $sheet, 'the dropdown covers exactly the yellow lines');
        assert_contains('<formula1>$A$11:$A$21</formula1>', $sheet, 'and points at the 11 listed ways to sell');
        assert_contains('<selection activeCell="A27"', $sheet, 'the cursor starts on the first yellow line');
        assert_contains('How many in the Shop now', $sheet);
        assert_contains('How many in the Warehouse now', $sheet);
        assert_contains('Also sold as (other units and their prices)', $sheet);
        $zip->close();
        unlink($out);
    },
];
