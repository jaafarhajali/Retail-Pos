<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Settings;
use App\Models\Setting;

/** Validates and saves the shop settings. Throws \DomainException with the message to show. */
final class SettingService
{
    public const ROUNDING_STEPS = [1000, 5000, 10000];

    /** key => [label, max length, required] */
    private const TEXT_FIELDS = [
        'shop_name'      => ['Shop name', 100, true],
        'shop_address'   => ['Address', 255, false],
        'shop_phone'     => ['Phone', 30, false],
        'receipt_header' => ['Receipt header', 255, false],
        'receipt_footer' => ['Receipt footer', 255, false],
    ];

    public function save(array $input): void
    {
        $values = [];
        foreach (self::TEXT_FIELDS as $key => [$label, $max, $required]) {
            $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if ($required && $value === '') {
                throw new \DomainException("{$label} is required.");
            }
            if (mb_strlen($value) > $max) {
                throw new \DomainException("{$label} must be at most {$max} characters.");
            }
            $values[$key] = $value;
        }

        $step = $input['lbp_rounding_step'] ?? '';
        if (!is_string($step) || !ctype_digit($step) || !in_array((int) $step, self::ROUNDING_STEPS, true)) {
            throw new \DomainException('Choose an LBP rounding step of 1,000, 5,000 or 10,000.');
        }
        $values['lbp_rounding_step'] = (string) (int) $step;

        $discount = $input['max_cashier_discount_pct'] ?? '';
        if (!is_string($discount) || !preg_match('/^\d{1,3}$/', trim($discount)) || (int) $discount > 100) {
            throw new \DomainException('The maximum cashier discount must be a whole number from 0 to 100.');
        }
        $values['max_cashier_discount_pct'] = (string) (int) $discount;

        $values['usd_denominations'] = $this->denominations($input['usd_denominations'] ?? '', 'USD');
        $values['lbp_denominations'] = $this->denominations($input['lbp_denominations'] ?? '', 'LBP');

        $settings = new Setting();
        $before = $settings->all();
        $settings->setMany($values);
        Settings::flush();

        $changed = [];
        foreach ($values as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[$key] = ['old' => $before[$key] ?? null, 'new' => $value];
            }
        }
        if ($changed !== []) {
            Audit::log('settings.updated', 'settings', null, $changed);
        }
    }

    public const LOGO_MAX_W = 600;
    public const LOGO_MAX_H = 300;

    /**
     * Shop logo for the sidebar, sign-in page and receipts: PNG (transparency kept), resized
     * to fit 600×300, saved under public/uploads/. Returns the file name.
     */
    public function saveLogo(string $tmpPath, int $size): string
    {
        if (!extension_loaded('gd')) {
            throw new \DomainException('Image support (GD) is not enabled on this server.');
        }
        if ($size > 5 * 1024 * 1024 || filesize($tmpPath) > 5 * 1024 * 1024) {
            throw new \DomainException('The logo must be 5 MB or smaller.');
        }
        $info = @getimagesize($tmpPath);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new \DomainException('Choose a JPG, PNG, WEBP or GIF image.');
        }
        if ((int) $info[0] * (int) $info[1] > 30_000_000) {
            throw new \DomainException('The image is too large (over 30 megapixels). Resize it first.');
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
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1, self::LOGO_MAX_W / $w, self::LOGO_MAX_H / $h);
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $target = imagecreatetruecolor($tw, $th);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 255, 255, 255, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $tw, $th, $w, $h);
        if (!is_dir(UPLOADS_PATH) && !mkdir(UPLOADS_PATH, 0777, true) && !is_dir(UPLOADS_PATH)) {
            throw new \RuntimeException('Cannot create the uploads folder.');
        }
        $file = 'logo-' . bin2hex(random_bytes(4)) . '.png';
        if (!imagepng($target, UPLOADS_PATH . '/' . $file, 6)) {
            throw new \RuntimeException('Could not save the logo.');
        }
        $this->removeLogo(false);
        (new Setting())->setMany(['shop_logo' => $file]);
        Settings::flush();
        Audit::log('settings.logo_set', 'settings', null, ['file' => $file, 'size' => "{$tw}x{$th}"]);

        return $file;
    }

    public function removeLogo(bool $audit = true): void
    {
        $current = Settings::get('shop_logo');
        if ($current !== '' && preg_match('/^logo-[a-f0-9]{8}\.png$/', $current) && is_file(UPLOADS_PATH . '/' . $current)) {
            unlink(UPLOADS_PATH . '/' . $current);
        }
        if ($current !== '') {
            (new Setting())->setMany(['shop_logo' => '']);
            Settings::flush();
            if ($audit) {
                Audit::log('settings.logo_removed', 'settings', null, ['file' => $current]);
            }
        }
    }

    /** "5, 20,1,20" → "20,5,1" (positive whole notes, no duplicates, largest first). */
    private function denominations(mixed $raw, string $currency): string
    {
        if (!is_string($raw)) {
            throw new \DomainException("{$currency} notes must be whole numbers separated by commas.");
        }
        $notes = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^\d{1,7}$/', $part) || (int) $part < 1) {
                throw new \DomainException("{$currency} notes must be positive whole numbers separated by commas.");
            }
            $notes[(int) $part] = true;
        }
        if ($notes === []) {
            throw new \DomainException("Enter at least one {$currency} note.");
        }
        $list = array_keys($notes);
        rsort($list);

        return implode(',', $list);
    }
}
