<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Models\Product;

/**
 * One optional photo per product (owner decision 2026-09-24): resized to fit
 * MAX_SIDE × MAX_SIDE, saved as JPEG under public/uploads/products/, shown on
 * the product form and (Phase 4) the POS tile.
 */
final class ProductImageService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_SIDE = 400;
    /** Decoding needs ~4 bytes per pixel: 50 MP ≈ 200 MB, inside XAMPP's 512 MB memory_limit. */
    public const MAX_PIXELS = 50_000_000;

    /** @return string the saved file name (e.g. "7-3f9a1c2e.jpg") */
    public function store(int $productId, string $tmpPath, int $size): string
    {
        $products = new Product();
        $product = $products->find($productId) ?? throw new \DomainException('Product not found.');
        if (!extension_loaded('gd')) {
            throw new \DomainException('Image support (GD) is not enabled on this server.');
        }
        if ($size > self::MAX_BYTES || filesize($tmpPath) > self::MAX_BYTES) {
            throw new \DomainException('The image must be 5 MB or smaller.');
        }
        $info = @getimagesize($tmpPath);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new \DomainException('Choose a JPG, PNG, WEBP or GIF image.');
        }
        // The header is read before decoding: a huge photo would exhaust PHP's memory and 500.
        if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            throw new \DomainException('The image is too large (over ' . intdiv(self::MAX_PIXELS, 1_000_000) . ' megapixels). Resize it first.');
        }
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => @imagecreatefromwebp($tmpPath),
            default      => @imagecreatefromgif($tmpPath),
        };
        if ($source === false) {
            throw new \DomainException('Choose a JPG, PNG, WEBP or GIF image.');
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            // Phone photos carry an EXIF orientation; re-encoding drops the tag, so rotate the pixels first.
            $source = self::upright($source, (int) (@exif_read_data($tmpPath)['Orientation'] ?? 1));
        }

        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1, self::MAX_SIDE / max($w, $h));   // never enlarge
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $target = imagecreatetruecolor($tw, $th);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));   // white behind transparency
        imagecopyresampled($target, $source, 0, 0, 0, 0, $tw, $th, $w, $h);

        $dir = UPLOADS_PATH . '/products';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        $file = $productId . '-' . bin2hex(random_bytes(4)) . '.jpg';
        if (!imagejpeg($target, $dir . '/' . $file, 85)) {
            throw new \RuntimeException('Could not save the image.');
        }
        imagedestroy($source);
        imagedestroy($target);

        $this->deleteFile($product['image_file']);
        $products->setImage($productId, $file);
        Audit::log('product.image_set', 'product', $productId, ['file' => $file, 'size' => "{$tw}x{$th}"]);

        return $file;
    }

    public function remove(int $productId): void
    {
        $products = new Product();
        $product = $products->find($productId) ?? throw new \DomainException('Product not found.');
        if ($product['image_file'] === null) {
            return;
        }
        $this->deleteFile($product['image_file']);
        $products->setImage($productId, null);
        Audit::log('product.image_removed', 'product', $productId, ['file' => $product['image_file']]);
    }

    /** Relative to public/, for <img src>. */
    public static function url(?string $file): ?string
    {
        return $file === null ? null : UPLOADS_URL . '/products/' . $file;
    }

    /** Apply an EXIF orientation (1–8) so the stored pixels are upright. imagerotate() turns counter-clockwise. */
    private static function upright(\GdImage $img, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 2:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $img;
            case 3:
                return imagerotate($img, 180, 0) ?: $img;
            case 4:
                imageflip($img, IMG_FLIP_VERTICAL);
                return $img;
            case 5:
                $r = imagerotate($img, -90, 0) ?: $img;
                imageflip($r, IMG_FLIP_HORIZONTAL);
                return $r;
            case 6:
                return imagerotate($img, -90, 0) ?: $img;
            case 7:
                $r = imagerotate($img, 90, 0) ?: $img;
                imageflip($r, IMG_FLIP_HORIZONTAL);
                return $r;
            case 8:
                return imagerotate($img, 90, 0) ?: $img;
            default:
                return $img;
        }
    }

    private function deleteFile(?string $file): void
    {
        if ($file !== null && preg_match('/^[0-9]+-[a-f0-9]{8}\.jpg$/', $file) && is_file(UPLOADS_PATH . '/products/' . $file)) {
            unlink(UPLOADS_PATH . '/products/' . $file);
        }
    }
}
