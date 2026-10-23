<?php
/**
 * php tools/installer/make-icon.php
 * Builds public/assets/brand/codexy.ico from codexy-mark.svg: Chrome renders the SVG at 256, 48, 32 and 16 pixels.
 * The small sizes go in as classic 32-bit bitmaps (what Explorer draws for shortcuts on every Windows), the 256 one
 * as PNG (the only form allowed at that size). Used by the setup for its own icon, the uninstaller, the desktop
 * shortcut, and by the pages as the browser-tab icon.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$svg = $root . '/public/assets/brand/codexy-mark.svg';
$ico = $root . '/public/assets/brand/codexy.ico';
$chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
if (!is_file($svg) || !is_file($chrome) || !function_exists('imagecreatefrompng')) {
    fwrite(STDERR, "needs $svg, Chrome and PHP's gd\n");
    exit(1);
}
$work = sys_get_temp_dir() . '/codexy-icon-' . bin2hex(random_bytes(3));
mkdir($work);
$entries = [];
// Chrome renders the mark once at 256 px (smaller windows come out empty); the small sizes are scaled down from it with gd.
$png = "$work/256.png";
$cmd = sprintf('"%s" --headless=new --disable-gpu --no-sandbox --hide-scrollbars --default-background-color=00000000 --user-data-dir="%s/profile" --window-size=256,256 --screenshot="%s" "file:///%s" >nul 2>&1',
    $chrome, $work, $png, str_replace('\\', '/', $svg));
exec($cmd);
if (!is_file($png)) {
    fwrite(STDERR, "Chrome did not render the mark\n");
    exit(1);
}
$big = imagecreatefrompng($png);
imagesavealpha($big, true);
$centre = imagecolorsforindex($big, imagecolorat($big, 128, 128));
if ($centre['alpha'] > 10) {
    fwrite(STDERR, "the picture is empty (transparent centre)\n");
    exit(1);
}
$entries[] = [256, (string) file_get_contents($png)];
foreach ([48, 32, 16] as $size) {
    $small = imagecreatetruecolor($size, $size);
    imagealphablending($small, false);
    imagesavealpha($small, true);
    imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
    imagecopyresampled($small, $big, 0, 0, 0, 0, $size, $size, 256, 256);
    $smallPng = "$work/$size.png";
    imagepng($small, $smallPng);
    $entries[] = [$size, bitmapEntry($smallPng, $size)];
}

/** A 32-bit BGRA bitmap with alpha and an empty AND mask, as an .ico entry (BITMAPINFOHEADER + rows bottom-up). */
function bitmapEntry(string $png, int $size): string
{
    $im = imagecreatefrompng($png);
    imagesavealpha($im, true);
    $header = pack('VVVvvVVVVVV', 40, $size, $size * 2, 1, 32, 0, $size * $size * 4, 0, 0, 0, 0);
    $pixels = '';
    for ($y = $size - 1; $y >= 0; $y--) {
        for ($x = 0; $x < $size; $x++) {
            $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
            $alpha = (int) round((127 - $c['alpha']) * 255 / 127);   // gd: 0 opaque … 127 transparent
            $pixels .= pack('CCCC', $c['blue'], $c['green'], $c['red'], $alpha);
        }
    }
    $maskRow = str_repeat("\0", (int) (ceil($size / 32) * 4));
    $mask = str_repeat($maskRow, $size);
    imagedestroy($im);

    return $header . $pixels . $mask;
}

// ICO: header, one directory entry per image, then the images
$count = count($entries);
$dir = '';
$data = '';
$offset = 6 + 16 * $count;
foreach ($entries as [$size, $bytes]) {
    $dir .= pack('CCCCvvVV', $size === 256 ? 0 : $size, $size === 256 ? 0 : $size, 0, 0, 1, 32, strlen($bytes), $offset);
    $data .= $bytes;
    $offset += strlen($bytes);
}
file_put_contents($ico, pack('vvv', 0, 1, $count) . $dir . $data);
foreach (glob("$work/*") ?: [] as $f) { is_dir($f) || @unlink($f); }
echo 'Written ', $ico, ' (', number_format(filesize($ico) / 1024, 1), " KB: 256 png, 48/32/16 bitmap)\n";
