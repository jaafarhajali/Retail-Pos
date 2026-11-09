<?php
/**
 * php bin/product-sheet.php [path.xlsx]
 *
 * Builds the Excel workbook the owner fills in to hand over his products (Aya, 2026-11-09):
 * one sheet to add products, one for extra units, one that lists what the system already has
 * (so he adds only what is missing and notes changes), and the lists behind the dropdowns.
 * Aya types the finished sheet into the system.
 *
 * Written without any library: an .xlsx is a zip of XML parts, and PHP's ZipArchive is enough.
 * Default output: storage/exports/products-to-fill-YYYY-MM-DD.xlsx
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\Quantity;

$out = $argv[1] ?? dirname(__DIR__) . '/storage/exports/products-to-fill-' . date('Y-m-d') . '.xlsx';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}
$pdo = Database::pdo();
$shopName = (string) (setting('shop_name', APP_NAME));

// ---- what the system holds today -------------------------------------------------------------
$categories = array_column($pdo->query('SELECT name FROM categories WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(), 'name');
$locations = $pdo->query('SELECT * FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();
$shop = null;
$warehouse = null;
foreach ($locations as $l) {
    if ((int) $l['is_default_sale'] === 1) {
        $shop = $l;
    }
    if ((int) $l['is_default_receive'] === 1) {
        $warehouse = $l;
    }
}
$shopLabel = $shop['name'] ?? 'Shop';
$warehouseLabel = $warehouse['name'] ?? 'Warehouse';

$existing = [];
$productRows = $pdo->query(
    'SELECT p.id, p.internal_code, p.name, p.base_unit, p.min_stock_base, p.stock_base, c.name AS category
     FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.is_active = 1 ORDER BY c.sort_order, c.name, p.name'
)->fetchAll();
foreach ($productRows as $p) {
    $units = $pdo->query('SELECT * FROM product_units WHERE product_id = ' . (int) $p['id'] . ' ORDER BY factor')->fetchAll();
    $unitText = [];
    foreach ($units as $u) {
        $prices = [];
        if ($u['retail_price'] !== null) {
            $prices[] = 'retail $' . number_format((float) $u['retail_price'], 2);
        }
        if ($u['wholesale_price'] !== null) {
            $prices[] = 'wholesale $' . number_format((float) $u['wholesale_price'], 2);
        }
        $unitText[] = $u['name'] . ' = ' . number_format((int) $u['factor']) . ' ' . $p['base_unit']
            . ($prices !== [] ? ' (' . implode(', ', $prices) . ')' : '') . ((int) $u['is_default_sale'] === 1 ? ' — main' : '');
    }
    $byPlace = [];
    foreach ($pdo->query('SELECT l.name, ps.stock_base FROM product_stocks ps JOIN locations l ON l.id = ps.location_id WHERE ps.product_id = ' . (int) $p['id']) as $s) {
        $byPlace[$s['name']] = (int) $s['stock_base'];
    }
    $existing[] = [
        $p['internal_code'], $p['name'], $p['category'] ?? '', $p['base_unit'], implode("\n", $unitText),
        Quantity::format($byPlace[$shopLabel] ?? 0, $units, $p['base_unit']),
        Quantity::format($byPlace[$warehouseLabel] ?? 0, $units, $p['base_unit']),
        $p['min_stock_base'] === null ? '' : Quantity::format((int) $p['min_stock_base'], $units, $p['base_unit']),
        ['', 3],   // the owner's notes: changes, missing units, wrong prices
    ];
}

// ---- the workbook ----------------------------------------------------------------------------
// A cell is a plain value (style 0) or [value, styleIndex]. Styles (see styles.xml below):
// 0 normal · 1 header · 2 example (grey italic) · 3 fill-in (yellow, bordered) · 4 wrapped text · 5 bold · 6 wrapped, bordered
$FILL_ROWS = 200;

$howTo = [
    [[$shopName . ' — product list to fill in', 5]],
    [''],
    [['What to do', 5]],
    [["1. Open the sheet 'Already in the system' first. Those products exist already — do not type them again. If something there is wrong or missing (a unit you also sell by, a price, a barcode, the stock), write it in the yellow column 'Changes / missing units' on that same line.", 4]],
    [["2. On the sheet 'Products', add every product that is NOT in the system yet: one line per product. The yellow cells are yours to fill; the columns with a * are needed, the others can stay empty.", 4]],
    [["3. If a product is sold in more than one unit — for example charcoal by the kilo AND by the box — write the main unit (the one sold most) on 'Products', and every other unit on the sheet 'Units', one line per extra unit, with the same product name.", 4]],
    [["4. Prices are in US dollars, per the unit you wrote on that line. Cost is what you pay the supplier for that unit.", 4]],
    [["5. Stock is what is really there today, counted in the unit you wrote on that line: one column for the " . $shopLabel . ", one for the " . $warehouseLabel . ". Leave empty if nothing is there.", 4]],
    [["6. Base unit is the smallest thing you count: piece, g (grams) or ml. A box of 10 kg of charcoal is 10000 g; a pack of 10 pieces is 10 piece; a bottle of 100 ml is 100 ml. Then '1 unit = how many base' says how big the unit is: Box 10kg = 10000, Pack of 10 = 10, kg = 1000, Piece = 1.", 4]],
    [["7. When you finish, send the file back. Nothing you type here changes the system by itself — it is typed in afterwards and checked with you.", 4]],
    [''],
    [['Two examples', 5]],
    [['Product name', 1], ['Category', 1], ['Base unit', 1], ['Sold mainly as', 1], ['1 unit = how many base', 1], ['Retail $', 1], ['Wholesale $', 1], ['Cost $', 1], ['Barcode', 1], ['Stock in ' . $shopLabel, 1], ['Stock in ' . $warehouseLabel, 1], ['Low-stock alert at', 1], ['On till grid?', 1], ['Price changes often?', 1]],
    [['Nakhla Mint 50g', 2], ['m3asal', 2], ['piece', 2], ['Piece', 2], [1, 2], [3, 2], [2.5, 2], [2, 2], ['6291100000012', 2], [40, 2], [100, 2], [10, 2], ['yes', 2], ['no', 2]],
    [['Fahem 2.5cm', 2], ['fa7em', 2], ['g', 2], ['Box 10kg', 2], [10000, 2], [34, 2], [32, 2], [28, 2], ['', 2], [7, 2], [40, 2], [2, 2], ['yes', 2], ['yes', 2]],
    [''],
    [["…and because Fahem is also sold by the kilo, one more line goes on the sheet 'Units':", 4]],
    [['Product name', 1], ['Unit name', 1], ['1 unit = how many base', 1], ['Retail $', 1], ['Wholesale $', 1], ['Barcode', 1]],
    [['Fahem 2.5cm', 2], ['kg', 2], [1000, 2], [4, 2], [3.8, 2], ['', 2]],
];

$productHead = [
    'Product name *', 'Category', 'Base unit * (piece / g / ml)', 'Sold mainly as * (unit name)', '1 unit = how many base? *',
    'Retail price $ * (per that unit)', 'Wholesale price $ (per that unit)', 'Cost $ (per that unit)', 'Barcode (of that unit)',
    'Stock now in ' . $shopLabel . ' (in that unit)', 'Stock now in ' . $warehouseLabel . ' (in that unit)', 'Low-stock alert at (in that unit)',
    'Show on the till grid? (yes / no)', 'Price changes often? (yes / no)', 'Notes',
];
$products = [array_map(static fn (string $h): array => [$h, 1], $productHead)];
for ($i = 0; $i < $FILL_ROWS; $i++) {
    $products[] = array_fill(0, count($productHead), ['', 3]);
}

$unitHead = ['Product name * (exactly as on Products or Already in the system)', 'Unit name *', '1 unit = how many base? *', 'Retail price $', 'Wholesale price $', 'Barcode'];
$units = [array_map(static fn (string $h): array => [$h, 1], $unitHead)];
for ($i = 0; $i < $FILL_ROWS; $i++) {
    $units[] = array_fill(0, count($unitHead), ['', 3]);
}

$existingHead = ['Code', 'Product', 'Category', 'Base unit', 'Units and prices', 'Stock in ' . $shopLabel, 'Stock in ' . $warehouseLabel, 'Low-stock alert at', 'Changes / missing units (write here)'];
$already = [array_map(static fn (string $h): array => [$h, 1], $existingHead)];
foreach ($existing as $row) {
    $already[] = array_map(static fn ($c): array => is_array($c) ? $c : [$c, 6], $row);
}
if ($existing === []) {
    $already[] = [['Nothing yet: the system is empty.', 2]];
}

$listRows = [[['Categories', 1], ['Base units', 1], ['Yes / no', 1]]];
$max = max(count($categories), 3);
for ($i = 0; $i < $max; $i++) {
    $listRows[] = [$categories[$i] ?? '', ['piece', 'g', 'ml'][$i] ?? '', ['yes', 'no'][$i] ?? ''];
}

$sheets = [
    ['name' => 'How to fill', 'rows' => $howTo, 'widths' => [1 => 28, 2 => 14, 3 => 12, 4 => 16, 5 => 20, 6 => 11, 7 => 13, 8 => 10, 9 => 16, 10 => 16, 11 => 20, 12 => 18, 13 => 13, 14 => 20], 'freeze' => false,
     'merge' => array_map(static fn (int $r): string => "A{$r}:N{$r}", [1, 3, 4, 5, 6, 7, 8, 9, 10, 12, 17])],
    ['name' => 'Products', 'rows' => $products, 'widths' => [1 => 30, 2 => 16, 3 => 16, 4 => 18, 5 => 14, 6 => 14, 7 => 15, 8 => 13, 9 => 18, 10 => 16, 11 => 18, 12 => 16, 13 => 14, 14 => 14, 15 => 30], 'freeze' => true,
     'validations' => [
         ['sqref' => 'B2:B' . ($FILL_ROWS + 1), 'list' => "'Lists'!\$A\$2:\$A\$" . ($max + 1), 'strict' => false],
         ['sqref' => 'C2:C' . ($FILL_ROWS + 1), 'list' => "'Lists'!\$B\$2:\$B\$4", 'strict' => true, 'error' => 'Choose piece, g or ml.'],
         ['sqref' => 'M2:N' . ($FILL_ROWS + 1), 'list' => "'Lists'!\$C\$2:\$C\$3", 'strict' => true, 'error' => 'Write yes or no.'],
     ]],
    ['name' => 'Units', 'rows' => $units, 'widths' => [1 => 44, 2 => 18, 3 => 22, 4 => 14, 5 => 16, 6 => 18], 'freeze' => true],
    ['name' => 'Already in the system', 'rows' => $already, 'widths' => [1 => 11, 2 => 28, 3 => 14, 4 => 10, 5 => 58, 6 => 20, 7 => 22, 8 => 20, 9 => 44], 'freeze' => true],
    ['name' => 'Lists', 'rows' => $listRows, 'widths' => [1 => 24, 2 => 12, 3 => 10], 'freeze' => true],
];

// ---- xlsx writer -----------------------------------------------------------------------------
$x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$col = static function (int $n): string {   // 1 → A, 27 → AA
    $s = '';
    while ($n > 0) {
        $m = ($n - 1) % 26;
        $s = chr(65 + $m) . $s;
        $n = intdiv($n - 1 - $m, 26);
    }

    return $s;
};
$sheetXml = static function (array $sheet) use ($x, $col): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0"' . ($sheet['name'] === 'How to fill' ? ' tabSelected="1"' : '') . '>'
        . ($sheet['freeze'] ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '') . '</sheetView></sheetViews>';
    $xml .= '<sheetFormatPr defaultRowHeight="15"/>';
    if (!empty($sheet['widths'])) {
        $xml .= '<cols>';
        foreach ($sheet['widths'] as $c => $w) {
            $xml .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }
    $xml .= '<sheetData>';
    foreach ($sheet['rows'] as $r => $cells) {
        $rowNo = $r + 1;
        $xml .= '<row r="' . $rowNo . '">';
        foreach ($cells as $c => $cell) {
            [$value, $style] = is_array($cell) ? [$cell[0], $cell[1]] : [$cell, 0];
            $ref = $col($c + 1) . $rowNo;
            if ($value === '' || $value === null) {
                $xml .= $style ? '<c r="' . $ref . '" s="' . $style . '"/>' : '';
            } elseif (is_int($value) || is_float($value)) {
                $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $x((string) $value) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    if (!empty($sheet['merge'])) {
        $xml .= '<mergeCells count="' . count($sheet['merge']) . '">';
        foreach ($sheet['merge'] as $m) {
            $xml .= '<mergeCell ref="' . $m . '"/>';
        }
        $xml .= '</mergeCells>';
    }
    if (!empty($sheet['validations'])) {
        $xml .= '<dataValidations count="' . count($sheet['validations']) . '">';
        foreach ($sheet['validations'] as $v) {
            $xml .= '<dataValidation type="list" allowBlank="1" showDropDown="0" showInputMessage="1" showErrorMessage="' . ($v['strict'] ? '1' : '0') . '"'
                . (isset($v['error']) ? ' errorTitle="Not in the list" error="' . $x($v['error']) . '"' : '') . ' sqref="' . $v['sqref'] . '">'
                . '<formula1>' . $x($v['list']) . '</formula1></dataValidation>';
        }
        $xml .= '</dataValidations>';
    }
    $xml .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
    $xml .= '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>';

    return $xml . '</worksheet>';
};

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="4">'
    . '<font><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
    . '<font><i/><sz val="11"/><color rgb="FF6B6B6B"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="4">'
    . '<fill><patternFill patternType="none"/></fill>'
    . '<fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF6CC"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="2">'
    . '<border><left/><right/><top/><bottom/><diagonal/></border>'
    . '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>'
    . '</borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="7">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                                   // 0 normal
    . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf>'   // 1 header
    . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>'                                                     // 2 example
    . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'                                                     // 3 fill-in
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'                   // 4 wrapped
    . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                     // 5 bold
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'   // 6 wrapped, bordered
    . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';

$zip = new ZipArchive();
if (file_exists($out)) {
    unlink($out);
}
if ($zip->open($out, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot write {$out}\n");
    exit(1);
}
$types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
foreach ($sheets as $i => $sheet) {
    $n = $i + 1;
    $types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $workbook .= '<sheet name="' . $x($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
    $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $sheetXml($sheet));
}
$types .= '</Types>';
$workbook .= '</sheets></workbook>';
$wbRels .= '</Relationships>';
$zip->addFromString('[Content_Types].xml', $types);
$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>');
$zip->addFromString('xl/workbook.xml', $workbook);
$zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
$zip->addFromString('xl/styles.xml', $styles);
$zip->close();

echo 'Written: ', $out, PHP_EOL;
echo '  ', count($existing), ' product(s) already in the system listed, ', count($categories), ' categories in the dropdown, ', $FILL_ROWS, ' empty lines to fill.', PHP_EOL;
