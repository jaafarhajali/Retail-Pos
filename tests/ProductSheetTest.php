<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Services\ProductService;
use App\Services\StockService;

/**
 * 2026-11-09 (Aya): bin/product-sheet.php builds the Excel workbook the owner fills in with his
 * products. It must be a real .xlsx (a zip of well-formed XML parts), list what the system already
 * has so he adds only what is missing, and carry the dropdowns that keep his answers typeable.
 */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the workbook opens as a zip, has the five sheets, lists the existing catalogue and carries the dropdowns' => function (): void {
        $svc = new ProductService();
        $cat = (new \App\Models\Category())->create('Tobacco', '#6c757d', 1);
        $p = make_product('Nakhla Mint', 'piece', $cat);
        $piece = $svc->addUnit($p, 'Piece', '1', false, true);
        $svc->setPrices($piece, '3.00', '2.50');
        (new StockService())->adjust($p, $piece, '40', 'opening', '', '1', TEST_ADMIN_ID);
        $out = dirname(__DIR__) . '/storage/exports/test-products-sheet.xlsx';
        @unlink($out);
        exec(sprintf('"%s" "%s" "%s" 2>&1', PHP_BINARY, dirname(__DIR__) . '/bin/product-sheet.php', $out), $lines, $rc);   // RETAIL_POS_ENV=test is inherited
        assert_same(0, $rc, implode("\n", $lines));
        assert_true(is_file($out), 'the file exists');

        $zip = new ZipArchive();
        assert_same(true, $zip->open($out), 'it is a zip');
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml',
                  'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml', 'xl/worksheets/sheet4.xml', 'xl/worksheets/sheet5.xml'] as $part) {
            $xml = $zip->getFromName($part);
            assert_true($xml !== false, $part . ' is in the zip');
            assert_true(simplexml_load_string($xml) !== false, $part . ' is well-formed XML');
        }
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        foreach (['How to fill', 'Products', 'Units', 'Already in the system', 'Lists'] as $name) {
            assert_contains('name="' . $name . '"', $workbook);
        }
        $already = (string) $zip->getFromName('xl/worksheets/sheet4.xml');
        assert_contains('Nakhla Mint', $already, 'the existing product is listed');
        assert_contains('Piece = 1 piece (retail $3.00, wholesale $2.50)', $already, 'with its unit and prices');
        assert_contains('40 Piece', $already, 'and its shop stock in its own unit');
        $products = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        assert_contains('<dataValidation type="list"', $products, 'dropdowns for category, base unit and yes/no');
        assert_contains('Stock now in Shop', $products);
        assert_contains('Stock now in Warehouse', $products);
        $lists = (string) $zip->getFromName('xl/worksheets/sheet5.xml');
        assert_contains('piece', $lists);
        assert_contains('Tobacco', $lists, 'the test category sits in the dropdown list');
        $zip->close();
        unlink($out);
    },
];
