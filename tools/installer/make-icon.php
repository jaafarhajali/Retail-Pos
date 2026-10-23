<?php
/**
 * php tools/installer/make-icon.php
 * Builds public/assets/brand/codexy.ico from codexy-mark.svg: Chrome renders the SVG at 256, 48, 32 and 16 pixels, and
 * the PNGs are packed into one .ico (Windows accepts PNG-compressed icon entries since Vista). Used by the setup for
 * its own icon, the uninstaller, the desktop shortcut, and by the pages as the browser-tab icon.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$svg = $root . '/public/assets/brand/codexy-mark.svg';
$ico = $root . '/public/assets/brand/codexy.ico';
$chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
if (!is_file($svg) || !is_file($chrome)) {
    fwrite(STDERR, "needs $svg and Chrome\n");
    exit(1);
}
$work = sys_get_temp_dir() . '/codexy-icon-' . bin2hex(random_bytes(3));
mkdir($work);
$entries = [];
foreach ([256, 48, 32, 16] as $size) {
    $html = "$work/$size.html";
    file_put_contents($html, "<!doctype html><html><head><meta charset=utf-8><style>html,body{margin:0;background:transparent}img{display:block;width:{$size}px;height:{$size}px}</style></head><body><img src=\"file:///" . str_replace('\\', '/', $svg) . "\"></body></html>");
    $png = "$work/$size.png";
    $cmd = sprintf('"%s" --headless=new --disable-gpu --no-sandbox --hide-scrollbars --default-background-color=00000000 --user-data-dir="%s/profile" --window-size=%d,%d --screenshot="%s" "file:///%s" >nul 2>&1',
        $chrome, $work, $size, $size, $png, str_replace('\\', '/', $html));
    exec($cmd);
    if (!is_file($png)) {
        fwrite(STDERR, "Chrome did not render $size px\n");
        exit(1);
    }
    $entries[] = [$size, (string) file_get_contents($png)];
}
// ICO: header, one directory entry per image, then the raw PNGs
$count = count($entries);
$dir = '';
$data = '';
$offset = 6 + 16 * $count;
foreach ($entries as [$size, $png]) {
    $dir .= pack('CCCCvvVV', $size === 256 ? 0 : $size, $size === 256 ? 0 : $size, 0, 0, 1, 32, strlen($png), $offset);
    $data .= $png;
    $offset += strlen($png);
}
file_put_contents($ico, pack('vvv', 0, 1, $count) . $dir . $data);
foreach (glob("$work/*") ?: [] as $f) { is_dir($f) || @unlink($f); }
echo 'Written ', $ico, ' (', number_format(filesize($ico) / 1024, 1), " KB, sizes 256/48/32/16)\n";
