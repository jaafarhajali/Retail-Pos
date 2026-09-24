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
        $source = match ($info['mime'] ?? '') {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => @imagecreatefromwebp($tmpPath),
            'image/gif'  => @imagecreatefromgif($tmpPath),
            default      => false,
        };
        if ($source === false) {
            throw new \DomainException('Choose a JPG, PNG, WEBP or GIF image.');
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

    private function deleteFile(?string $file): void
    {
        if ($file !== null && preg_match('/^[0-9]+-[a-f0-9]{8}\.jpg$/', $file) && is_file(UPLOADS_PATH . '/products/' . $file)) {
            unlink(UPLOADS_PATH . '/products/' . $file);
        }
    }
}
