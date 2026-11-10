<?php
/**
 * php bin/product-sheet.php [path.xlsx]
 *
 * Builds the Excel sheet the owner fills in to hand over his products (Aya, 2026-11-09; reworked 2026-11-10 until it
 * mirrors the Add-product screen): one tab, nothing from the catalogue on it, no prices and no quantities (he sets those
 * in the system afterwards). Six short steps, the same choices as the screen — How is it sold? (by piece / weight /
 * volume), Sold as (ProductService::UNIT_TYPES) and what a Pack / Box … holds, any number — three grey example lines,
 * then 200 yellow lines. Aya types it in.
 *
 * Written without any library: an .xlsx is a zip of XML parts, and PHP's ZipArchive is enough.
 * Default output: storage/exports/products-to-fill-YYYY-MM-DD.xlsx
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\ProductService;

$out = $argv[1] ?? dirname(__DIR__) . '/storage/exports/products-to-fill-' . date('Y-m-d') . '.xlsx';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}
$shopName = (string) (setting('shop_name', APP_NAME));

// ---- the same choices as the Add-product screen --------------------------------------------
// "How is it sold?" (by piece / weight / volume), then "Sold as" from ProductService::UNIT_TYPES, then what a Pack / Box … holds.
$soldAs = implode(',', array_keys(ProductService::UNIT_TYPES));   // Piece,Dozen,g,kg,ml,L,Pack,Box,Bag,Roll,Bottle
$guide = [
    ['By piece',  'Piece',                          'one piece'],
    ['By piece',  'Dozen',                          '12 pieces'],
    ['By piece',  'Pack, Box, Bag, Roll, Bottle',   'you write how many pieces it holds in column E: 5 pieces, 6 pieces, 20 pieces …'],
    ['By weight', 'g',                              'one gram'],
    ['By weight', 'kg',                             'one kilo'],
    ['By weight', 'Pack, Box, Bag, Roll, Bottle',   'you write the weight it holds in column E: 250 g, 1 kg, 10 kg …'],
    ['By volume', 'ml',                             'one millilitre'],
    ['By volume', 'L',                              'one litre'],
    ['By volume', 'Pack, Box, Bag, Roll, Bottle',   'you write the volume it holds in column E: 100 ml, 1 L …'],
];

// ---- the one sheet ---------------------------------------------------------------------------
// A cell is a plain value (style 0) or [value, styleIndex]. Styles (see styles.xml below):
// 0 normal · 1 header · 2 example (grey italic) · 3 fill-in (yellow, bordered) · 4 wrapped text · 5 bold · 6 bordered
$FILL_ROWS = 200;
$COLS = 9;
$rows = [];
$heights = [];
$merge = [];
$last = static function () use (&$rows): int {   // the row number of the last row added (1-based)
    return count($rows);
};
$text = static function (string $s, int $style = 4) use (&$rows, &$heights, &$merge): void {
    $rows[] = [[$s, $style]];
    $r = count($rows);
    $merge[] = "A{$r}:I{$r}";
    if ($style === 4) {
        $heights[$r] = 15 * (int) ceil(mb_strlen($s) / 170) + 4;
    }
};

$text($shopName . ' — my products', 5);
$rows[] = [''];
$text("1. Write one line per product you sell, in the yellow cells. The three grey lines show how. The questions are the same as on the Add product screen of the system.");
$text("2. Column C — How is it sold? By piece (tins, hoses, bowls, cans), By weight (charcoal or tobacco that you weigh) or By volume (liquids that you pour).");
$text("3. Column D — Sold as: click the cell, a small arrow opens the list. Piece, Dozen, g, kg, ml, L are complete by themselves. For Pack, Box, Bag, Roll or Bottle write in column E what it holds — a pack can hold any number: 5 pieces, 6 pieces, 250 g, 10 kg, 100 ml.");
$text("4. If you also sell the same product another way (charcoal by the box AND by the kilo), put the second way in columns F and G.");
$text("5. No prices and no quantities here: you will put them in the system yourself, product by product, once everything is in. This file is only the list of what you sell.");
$text("6. When you finish, send the file back.");
$rows[] = [''];

$text('The choices (the same as in the system):', 5);
$rows[] = [['How is it sold?', 1], ['Sold as', 1], ['What it is / what you write in Holds', 1]];
foreach ($guide as [$how, $as, $what]) {
    $rows[] = [[$how, 6], [$as, 6], [$what, 6]];
}
$rows[] = [''];

$text('My products', 5);
$head = [
    'Product name *', 'Category (anything: m3asal, fa7em, vape …)', 'How is it sold? *', 'Sold as *',
    'Holds (only for Pack / Box / Bag / Roll / Bottle: 5 pieces, 10 kg, 100 ml …)',
    'Also sold as (a second way, if any)', 'Holds (for that second way)', 'Barcode (if you have it)', 'Notes',
];
$rows[] = array_map(static fn (string $h): array => [$h, 1], $head);
$heights[$last()] = 62;
$rows[] = [['Nakhla Mint 50g', 2], ['m3asal', 2], ['By piece', 2], ['Piece', 2], ['', 2], ['', 2], ['', 2], ['6291100000012', 2], ['example', 2]];
$rows[] = [['Hose Classic', 2], ['accessories', 2], ['By piece', 2], ['Pack', 2], ['5 pieces', 2], ['Piece', 2], ['', 2], ['', 2], ['example: sold by the pack of 5 and one by one', 2]];
$rows[] = [['Fahem 2.5cm', 2], ['fa7em', 2], ['By weight', 2], ['Box', 2], ['10 kg', 2], ['kg', 2], ['', 2], ['', 2], ['example: sold by the box and by the kilo', 2]];
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
    'widths' => [1 => 30, 2 => 28, 3 => 18, 4 => 16, 5 => 40, 6 => 24, 7 => 26, 8 => 20, 9 => 40],
    'active' => 'A' . $firstFill,
    'validations' => [
        ['sqref' => "C{$firstFill}:C{$lastFill}", 'list' => '"By piece,By weight,By volume"', 'strict' => true, 'error' => 'Choose By piece, By weight or By volume.'],
        ['sqref' => "D{$firstFill}:D{$lastFill} F{$firstFill}:F{$lastFill}", 'list' => '"' . $soldAs . '"', 'strict' => false],
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
echo '  one sheet, the same choices as the Add product screen, ', $FILL_ROWS, ' empty lines to fill; nothing from the catalogue.', PHP_EOL;
