<?php
/**
 * php bin/product-sheet.php [path.xlsx]
 *
 * Builds the Excel sheet the owner fills in to hand over his products (Aya, 2026-11-09; one tab, nothing
 * from the catalogue on it and as easy as possible, 2026-11-10): five short steps, every unit the system has
 * (Piece, Dozen, kg … and the named ones in use: Pack of 10, Box 10kg) with a plain meaning — a dropdown that
 * still accepts a new word, which tells us which unit to add —, two grey example lines, then 200 yellow lines in plain words. No prices and no quantities:
 * filling the list may take him a month and they would be stale; he sets them in the system afterwards. Aya types it in.
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
$shopName = (string) (setting('shop_name', APP_NAME));

// ---- every unit the system has, with a plain meaning -----------------------------------------
// The six measures the system always knows, then every named unit in use on a product (Pack of 10, Box 10kg …).
$meaning = static function (string $base, int $factor): string {
    if ($base === 'piece') {
        return $factor === 1 ? 'one piece' : $factor . ' pieces';
    }
    if ($base === 'g') {
        return $factor === 1 ? 'one gram' : ($factor % 1000 === 0 ? ($factor / 1000) . ' kg' : $factor . ' g');
    }

    return $factor === 1 ? 'one millilitre' : ($factor % 1000 === 0 ? ($factor / 1000) . ' litre' : $factor . ' ml');
};
$unitRows = [];
foreach (ProductService::UNIT_TYPES as $name => $def) {
    if ($def['factor'] !== null) {
        $unitRows[$name] = $meaning($def['bases'][0], (int) $def['factor']);
    }
}
$inUse = Database::pdo()->query(
    'SELECT u.name, p.base_unit, u.factor FROM product_units u JOIN products p ON p.id = u.product_id
     WHERE p.is_active = 1 GROUP BY u.name, p.base_unit, u.factor ORDER BY p.base_unit, u.factor, u.name'
)->fetchAll();
foreach ($inUse as $u) {
    $unitRows[$u['name']] ??= $meaning($u['base_unit'], (int) $u['factor']);
}

// ---- the one sheet ---------------------------------------------------------------------------
// A cell is a plain value (style 0) or [value, styleIndex]. Styles (see styles.xml below):
// 0 normal · 1 header · 2 example (grey italic) · 3 fill-in (yellow, bordered) · 4 wrapped text · 5 bold · 6 bordered
$FILL_ROWS = 200;
$COLS = 7;
$rows = [];
$heights = [];
$merge = [];
$last = static function () use (&$rows): int {   // the row number of the last row added (1-based)
    return count($rows);
};
$text = static function (string $s, int $style = 4) use (&$rows, &$heights, &$merge): void {
    $rows[] = [[$s, $style]];
    $r = count($rows);
    $merge[] = "A{$r}:G{$r}";
    if ($style === 4) {
        $heights[$r] = 15 * (int) ceil(mb_strlen($s) / 150) + 4;
    }
};

$text($shopName . ' — my products', 5);
$rows[] = [''];
$text("1. Write one line per product you sell, in the yellow cells. The two grey lines show how.");
$text("2. Unit: in column C choose the unit you sell this product in — click the cell and a small arrow opens the list. The list 'Units in the system' below shows what each one means. If you sell the same product in more than one unit (charcoal by the box and by the kilo), put the others in columns D and E.");
$text("3. If your unit is not in the list, just write it with its size: Box of 6, Pack of 20, Box 5kg, Bottle 100ml. We will add it.");
$text("4. No prices and no quantities here: you will put them in the system yourself, product by product, once everything is in. This file is only the list of what you sell.");
$text("5. When you finish, send the file back.");
$rows[] = [''];

$text('Units in the system (what you can choose in columns C, D and E):', 5);
$rows[] = [['Unit', 1], ['Meaning', 1]];
$listTop = $last() + 1;
foreach ($unitRows as $name => $what) {
    $rows[] = [[$name, 6], [$what, 6]];
}
$unitList = '$A$' . $listTop . ':$A$' . $last();
$rows[] = [''];

$text('My products', 5);
$head = ['Product name *', 'Category (anything: m3asal, fa7em, vape …)', 'Unit *', 'Unit 2 (if sold in another unit too)', 'Unit 3', 'Barcode (if you have it)', 'Notes'];
$rows[] = array_map(static fn (string $h): array => [$h, 1], $head);
$heights[$last()] = 48;
$rows[] = [['Nakhla Mint 50g', 2], ['m3asal', 2], ['Piece', 2], ['', 2], ['', 2], ['6291100000012', 2], ['example', 2]];
$rows[] = [['Fahem 2.5cm', 2], ['fa7em', 2], ['Box 10kg', 2], ['kg', 2], ['', 2], ['', 2], ['example', 2]];
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
    'widths' => [1 => 32, 2 => 30, 3 => 20, 4 => 30, 5 => 20, 6 => 22, 7 => 30],
    'active' => 'A' . $firstFill,
    'validations' => [
        ['sqref' => "C{$firstFill}:E{$lastFill}", 'list' => $unitList, 'strict' => false],
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
echo '  one sheet: ', count($unitRows), ' units to pick from, ', $FILL_ROWS, ' empty lines to fill; nothing from the catalogue.', PHP_EOL;
