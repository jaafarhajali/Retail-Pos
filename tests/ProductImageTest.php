<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Models\Product;
use App\Services\ProductImageService;

/** Write a PNG of the given size to a temp file and return [path, bytes]. */
$png = static function (int $w, int $h): array {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 230, 126, 34));
    $path = tempnam(sys_get_temp_dir(), 'rpos');
    imagepng($img, $path);
    imagedestroy($img);

    return [$path, filesize($path)];
};

/** A JPEG whose EXIF says "rotate to display" (orientation 6 = 90° clockwise), like a portrait phone photo. */
$jpegWithOrientation = static function (int $w, int $h, int $orientation): array {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 230, 126, 34));
    ob_start();
    imagejpeg($img, null, 90);
    $jpeg = (string) ob_get_clean();
    imagedestroy($img);
    // Minimal EXIF APP1 segment: little-endian TIFF with one IFD0 entry, tag 0x0112 (Orientation), SHORT, count 1.
    $tiff = "II\x2A\x00" . pack('V', 8) . pack('v', 1) . pack('vvV', 0x0112, 3, 1) . pack('v', $orientation) . "\x00\x00" . pack('V', 0);
    $app1 = "Exif\x00\x00" . $tiff;
    $segment = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;
    $path = tempnam(sys_get_temp_dir(), 'rpos');
    file_put_contents($path, substr($jpeg, 0, 2) . $segment . substr($jpeg, 2));

    return [$path, filesize($path)];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a portrait phone JPEG (EXIF orientation 6) is saved upright' => function () use ($jpegWithOrientation): void {
        $id = make_product('Hookah');
        [$path, $size] = $jpegWithOrientation(100, 50, 6);
        assert_same(6, (int) (@exif_read_data($path)['Orientation'] ?? 0), 'test file carries the tag');
        $file = (new ProductImageService())->store($id, $path, $size);
        $info = getimagesize(UPLOADS_PATH . '/products/' . $file);
        assert_same([50, 100], [$info[0], $info[1]], 'rotated to portrait');
        assert_same(false, @exif_read_data(UPLOADS_PATH . '/products/' . $file)['Orientation'] ?? false, 'no stale tag left');
    },

    'an image with absurd pixel dimensions is refused before it is decoded' => function (): void {
        $id = make_product('Hose');
        // A PNG signature + IHDR claiming 20,000 × 20,000 px: getimagesize reads the header only.
        $ihdr = pack('NN', 20000, 20000) . "\x08\x02\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $path = tempnam(sys_get_temp_dir(), 'rpos');
        file_put_contents($path, $png);
        $e = assert_throws(DomainException::class, fn () => (new ProductImageService())->store($id, $path, strlen($png)));
        assert_contains('megapixel', $e->getMessage());
    },

    'GD is enabled (Task 8 Step 1)' => function (): void {
        assert_true(extension_loaded('gd'), 'enable extension=gd in C:\xampp\php\php.ini');
    },

    'a PNG is resized to fit 400×400 and saved as a JPEG under the product folder' => function () use ($png): void {
        $id = make_product('Charcoal', 'g');
        [$path, $size] = $png(1000, 500);
        $file = (new ProductImageService())->store($id, $path, $size);
        assert_true((bool) preg_match('/^' . $id . '-[a-f0-9]{8}\.jpg$/', $file), "file name {$file}");
        $saved = UPLOADS_PATH . '/products/' . $file;
        assert_true(is_file($saved));
        $info = getimagesize($saved);
        assert_same([400, 200], [$info[0], $info[1]]);
        assert_same('image/jpeg', $info['mime']);
        assert_same($file, (new Product())->find($id)['image_file']);
        assert_same('uploads/test/products/' . $file, ProductImageService::url($file));
    },

    'a small image is not enlarged and a second upload replaces the first file' => function () use ($png): void {
        $id = make_product('Hose');
        [$path, $size] = $png(100, 80);
        $first = (new ProductImageService())->store($id, $path, $size);
        $info = getimagesize(UPLOADS_PATH . '/products/' . $first);
        assert_same([100, 80], [$info[0], $info[1]]);
        [$path2, $size2] = $png(300, 300);
        $second = (new ProductImageService())->store($id, $path2, $size2);
        assert_false(is_file(UPLOADS_PATH . '/products/' . $first), 'old file removed');
        assert_true(is_file(UPLOADS_PATH . '/products/' . $second));
    },

    'files that are not images, or too big, are refused' => function () use ($png): void {
        $id = make_product('Hose');
        $text = tempnam(sys_get_temp_dir(), 'rpos');
        file_put_contents($text, '<?php echo 1;');
        assert_throws(DomainException::class, fn () => (new ProductImageService())->store($id, $text, 13));
        [$path, $size] = $png(10, 10);
        assert_throws(DomainException::class, fn () => (new ProductImageService())->store($id, $path, ProductImageService::MAX_BYTES + 1));
        assert_same(null, (new Product())->find($id)['image_file']);
    },

    'remove deletes the file and clears the column; url() of nothing is null' => function () use ($png): void {
        $id = make_product('Hose');
        [$path, $size] = $png(50, 50);
        $file = (new ProductImageService())->store($id, $path, $size);
        (new ProductImageService())->remove($id);
        assert_false(is_file(UPLOADS_PATH . '/products/' . $file));
        assert_same(null, (new Product())->find($id)['image_file']);
        assert_same(null, ProductImageService::url(null));
        (new ProductImageService())->remove($id);   // removing twice is harmless
    },
];
