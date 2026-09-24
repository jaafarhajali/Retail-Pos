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

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
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
