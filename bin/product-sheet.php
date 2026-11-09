<?php
/**
 * php bin/product-sheet.php [path.xlsx]
 *
 * Builds the Excel sheet the owner fills in to hand over his products (Aya, 2026-11-09; one tab only
 * and no existing products on it, 2026-11-10): a single sheet with the steps, the unit names and
 * categories the system already knows (so he picks from them, and a name we have not seen tells us
 * which unit to add), two grey example lines, then 200 yellow lines to fill. Aya types it into the system.
 *
 * Written without any library: an .xlsx is a zip of XML parts, and PHP's ZipArchive is enough.
 * Default output: storage/exports/products-to-fill-YYYY-MM-DD.xlsx
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\ProductService;

$out = $argv[1] ?? dirname(__DIR__) . '/storage/exports/products-to-fill-' . date('Y-m-d') . '.xlsx';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}
$pdo = Database::pdo();
$shopName = (string) (setting('shop_name', APP_NAME));

// ---- what the system knows today: the names he should reuse ---------------------------------
$categories = array_column($pdo->query('SELECT name FROM categories WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(), 'name');
// the unit types the system accepts (ProductService::UNIT_TYPES): a fixed size, or one the owner writes (Box of 6, Box 10kg …)
$unitRows = [];
foreach (ProductService::UNIT_TYPES as $name => $def) {
    $unitRows[] = [
        'name' => $name,
        'counts' => implode(' / ', $def['bases']),
        'size' => $def['factor'] === null ? 'you write it: Box of 6 = 6, Box 10kg = 10000' : (string) $def['factor'],
    ];
}
$shop = $pdo->query('SELECT name FROM locations WHERE is_default_sale = 1 AND is_active = 1')->fetchColumn();
$warehouse = $pdo->query('SELECT name FROM locations WHERE is_default_receive = 1 AND is_active = 1')->fetchColumn();
$shopLabel = is_string($shop) && $shop !== '' ? $shop : 'Shop';
$warehouseLabel = is_string($warehouse) && $warehouse !== '' ? $warehouse : 'Warehouse';

// ---- the one sheet ---------------------------------------------------------------------------
// A cell is a plain value (style 0) or [value, styleIndex]. Styles (see styles.xml below):
// 0 normal · 1 header · 2 example (grey italic) · 3 fill-in (yellow, bordered) · 4 wrapped text · 5 bold · 6 bordered
$FILL_ROWS = 200;
$COLS = 16;
$rows = [];
$heights = [];
$merge = [];
$last = static fn (): int => count($rows);   // the row number of the last row added (1-based)

$text = static function (string $s, int $style = 4) use (&$rows, &$heights, &$merge, $COLS): void {
    $rows[] = [[$s, $style]];
    $r = count($rows);
    $merge[] = "A{$r}:P{$r}";
    if ($style === 4) {
        $heights[$r] = 15 * (int) ceil(mb_strlen($s) / 230) + 4;
    }
};

$text($shopName . ' — product list to fill in', 5);
$rows[] = [''];
$text('How to fill', 5);
$text("1. One line per product you sell. The yellow cells are yours to fill; the columns with a * are needed, the others can stay empty. The two grey lines are examples — write under them, do not change them.");
$text("2. 'Sold mainly as' is the unit you sell it in most. Pick it from the list 'Units the system has' below (column D also offers them): Piece, kg, Box … For Pack, Box, Bag, Roll and Bottle you also say the size in column E, and the system names it for you (Box of 6, Box 10kg). If you sell in a unit that is not in the list, just write its name — that tells us which unit to add.");
$text("3. Base unit is the smallest thing you count: piece, g (grams) or ml. Then '1 unit = how many base' says how big the unit is: Piece = 1, Pack of 10 = 10, kg = 1000, Box 10kg = 10000 (10 kg = 10000 g), a bottle of 100 ml = 100.");
$text("4. If a product is also sold in other units — for example charcoal by the box AND by the kilo — write them in the column 'Other units it is also sold in', like: kg = 1000, retail 4, wholesale 3.8");
$text("5. Prices are in US dollars, per the unit you wrote on that line. Cost is what you pay the supplier for that unit.");
$text("6. Stock is what is really there today, counted in that same unit: one column for the " . $shopLabel . ", one for the " . $warehouseLabel . ". Leave empty if nothing is there.");
$text("7. When you finish, send the file back. Nothing you type here changes the system by itself — it is typed in afterwards and checked with you.");
$rows[] = [''];

$text('Units the system has — pick one of these for column D (it offers them as a dropdown). The categories are the choices of column B.', 5);
$rows[] = [['Unit', 1], ['What it counts', 1], ['1 unit = how many base', 1], '', ['Categories in the system', 1]];
$listTop = $last() + 1;
$n = max(count($unitRows), count($categories), 1);
for ($i = 0; $i < $n; $i++) {
    $u = $unitRows[$i] ?? null;
    $rows[] = [
        [$u['name'] ?? '', 6], [$u['counts'] ?? '', 6], [$u['size'] ?? '', 6], '',
        [$categories[$i] ?? '', 6],
    ];
}
$listBottom = $last();
$unitList = '$A$' . $listTop . ':$A$' . ($listTop + max(count($unitRows), 1) - 1);
$categoryList = '$E$' . $listTop . ':$E$' . ($listTop + max(count($categories), 1) - 1);
$rows[] = [''];

$text('Your products — one line each', 5);
$head = [
    'Product name *', 'Category', 'Base unit * (piece / g / ml)', 'Sold mainly as * (unit name — see the list above)', '1 unit = how many base? *',
    'Retail price $ * (per that unit)', 'Wholesale price $ (per that unit)', 'Cost $ (per that unit)', 'Barcode (of that unit)',
    'Stock now in ' . $shopLabel . ' (in that unit)', 'Stock now in ' . $warehouseLabel . ' (in that unit)', 'Low-stock alert at (in that unit)',
    'Show on the till grid? (yes / no)', 'Price changes often? (yes / no)', 'Other units it is also sold in (name = how many base, retail $, wholesale $)', 'Notes',
];
$rows[] = array_map(static fn (string $h): array => [$h, 1], $head);
$headRow = $last();
$heights[$headRow] = 48;
$rows[] = [['Nakhla Mint 50g', 2], ['m3asal', 2], ['piece', 2], ['Piece', 2], [1, 2], [3, 2], [2.5, 2], [2, 2], ['6291100000012', 2], [40, 2], [100, 2], [10, 2], ['yes', 2], ['no', 2], ['', 2], ['example', 2]];
$rows[] = [['Fahem 2.5cm', 2], ['fa7em', 2], ['g', 2], ['Box 10kg', 2], [10000, 2], [34, 2], [32, 2], [28, 2], ['', 2], [7, 2], [40, 2], [2, 2], ['yes', 2], ['yes', 2], ['kg = 1000, retail 4, wholesale 3.8', 2], ['example', 2]];
$firstFill = $last() + 1;
for ($i = 0; $i < $FILL_ROWS; $i++) {
    $rows[] = array_fill(0, $COLS, ['', 3]);
}
$lastFill = $last();

$sheet = [
    'name' => 'Products',
    'rows' => $rows,
    'heights' => $heights,
    'merge' => $merge,
    'widths' => [1 => 30, 2 => 16, 3 => 16, 4 => 22, 5 => 14, 6 => 14, 7 => 15, 8 => 13, 9 => 18, 10 => 16, 11 => 18, 12 => 16, 13 => 14, 14 => 14, 15 => 44, 16 => 30],
    'active' => 'A' . $firstFill,
    'validations' => [
        ['sqref' => "B{$firstFill}:B{$lastFill}", 'list' => $categoryList, 'strict' => false],
        ['sqref' => "C{$firstFill}:C{$lastFill}", 'list' => '"piece,g,ml"', 'strict' => true, 'error' => 'Choose piece, g or ml.'],
        ['sqref' => "D{$firstFill}:D{$lastFill}", 'list' => $unitList, 'strict' => false],
        ['sqref' => "M{$firstFill}:N{$lastFill}", 'list' => '"yes,no"', 'strict' => true, 'error' => 'Write yes or no.'],
    ],
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
    $xml .= '<sheetViews><sheetView workbookViewId="0" tabSelected="1">'
        . (isset($sheet['active']) ? '<selection activeCell="' . $sheet['active'] . '" sqref="' . $sheet['active'] . '"/>' : '') . '</sheetView></sheetViews>';
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
        $ht = $sheet['heights'][$rowNo] ?? null;
        $xml .= '<row r="' . $rowNo . '"' . ($ht !== null ? ' ht="' . $ht . '" customHeight="1"' : '') . '>';
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
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'                                                                   // 6 bordered
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
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '</Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>');
$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="' . $x($sheet['name']) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '</Relationships>');
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml($sheet));
$zip->addFromString('xl/styles.xml', $styles);
$zip->close();

echo 'Written: ', $out, PHP_EOL;
echo '  one sheet: ', count($unitRows), ' unit name(s) and ', count($categories), ' categories from the system to pick from, ', $FILL_ROWS, ' empty lines to fill.', PHP_EOL;
