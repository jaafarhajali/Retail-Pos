<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Models\Attachment;
use App\Models\Expense;
use App\Models\Purchase;

/**
 * The paper behind a record (owner, through Aya, 2026-10-18): the supplier's invoice on a purchase, the receipt on an
 * expense; a photo or a PDF, several per record, under public/uploads/attachments/ (in every backup, never in git).
 * A photo is re-encoded as a JPEG no wider than MAX_SIDE so a phone picture stays readable but small; a PDF is kept as
 * it is. Evidence only: the purchase or the expense does not change.
 */
final class AttachmentService
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MAX_SIDE = 1600;
    public const MAX_PIXELS = 50_000_000;
    /** The name of the file field on every form that takes attachments. */
    public const FIELD = 'attachments';

    public function __construct(private readonly Attachment $attachments = new Attachment())
    {
    }

    /**
     * @param 'purchase'|'expense' $owner
     * @param array{name?: mixed, tmp_name?: mixed, size?: mixed, error?: mixed} $upload one file, as uploads() splits them
     * @return int the attachment id
     */
    public function store(string $owner, int $ownerId, array $upload, int $userId): int
    {
        $record = match ($owner) {
            'purchase' => (new Purchase())->find($ownerId) ?? throw new \DomainException('Purchase not found.'),
            'expense'  => (new Expense())->find($ownerId) ?? throw new \DomainException('Expense not found.'),
            default    => throw new \InvalidArgumentException("Unknown attachment owner {$owner}"),
        };
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \DomainException('The file is too large for the server. Keep it under 8 MB.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \DomainException('The file did not arrive. Try again.');
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || $size > self::MAX_BYTES || filesize($tmp) > self::MAX_BYTES) {
            throw new \DomainException('The file must be 8 MB or smaller.');
        }
        $original = mb_substr(trim((string) ($upload['name'] ?? '')), 0, 150) ?: 'file';

        $dir = UPLOADS_PATH . '/attachments';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        $stem = $owner[0] . $ownerId . '-' . bin2hex(random_bytes(4));

        if (str_starts_with((string) file_get_contents($tmp, false, null, 0, 8), '%PDF-')) {
            $file = $stem . '.pdf';
            if (!copy($tmp, $dir . '/' . $file)) {
                throw new \RuntimeException('Could not save the file.');
            }
            $mime = 'application/pdf';
        } else {
            $info = @getimagesize($tmp);
            $imageMime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
            if (!in_array($imageMime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                throw new \DomainException('Choose a photo (JPG, PNG, WEBP) or a PDF.');
            }
            if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
                throw new \DomainException('The photo is too large (over ' . intdiv(self::MAX_PIXELS, 1_000_000) . ' megapixels). Resize it first.');
            }
            if (!extension_loaded('gd')) {
                throw new \DomainException('Image support (GD) is not enabled on this server.');
            }
            $source = match ($imageMime) {
                'image/jpeg' => @imagecreatefromjpeg($tmp),
                'image/png'  => @imagecreatefrompng($tmp),
                'image/webp' => @imagecreatefromwebp($tmp),
                default      => @imagecreatefromgif($tmp),
            };
            if ($source === false) {
                throw new \DomainException('Choose a photo (JPG, PNG, WEBP) or a PDF.');
            }
            if ($imageMime === 'image/jpeg' && function_exists('exif_read_data')) {
                $source = ProductImageService::upright($source, (int) (@exif_read_data($tmp)['Orientation'] ?? 1));   // phone photos
            }
            $w = imagesx($source);
            $h = imagesy($source);
            $scale = min(1, self::MAX_SIDE / max($w, $h));
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));
            $target = imagecreatetruecolor($tw, $th);
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $tw, $th, $w, $h);
            $file = $stem . '.jpg';
            if (!imagejpeg($target, $dir . '/' . $file, 82)) {
                throw new \RuntimeException('Could not save the photo.');
            }
            imagedestroy($source);
            imagedestroy($target);
            $mime = 'image/jpeg';
        }
        $bytes = (int) filesize($dir . '/' . $file);

        $id = $this->attachments->create($owner, $ownerId, ['file' => $file, 'original_name' => $original, 'mime' => $mime, 'size_bytes' => $bytes, 'user_id' => $userId]);
        Audit::log($owner . '.attached', $owner, $ownerId, ['no' => $record['purchase_no'] ?? $record['expense_no'] ?? null, 'file' => $file, 'name' => $original, 'bytes' => $bytes]);

        return $id;
    }

    /**
     * Saves every file posted under FIELD for one record; a bad file never loses the record.
     *
     * @return array{0: int, 1: ?string} how many were saved, and the first problem met ("photo.heic: Choose a photo…")
     */
    public function storeAll(string $owner, int $ownerId, int $userId): array
    {
        $saved = 0;
        $problem = null;
        foreach (self::uploads($_FILES[self::FIELD] ?? null) as $upload) {
            try {
                $this->store($owner, $ownerId, $upload, $userId);
                $saved++;
            } catch (\DomainException $e) {
                $problem ??= $upload['name'] . ': ' . $e->getMessage();
            }
        }

        return [$saved, $problem];
    }

    /**
     * Every file of a multiple <input type="file"> posted under one name, as separate $_FILES-shaped entries; empty
     * slots are skipped.
     *
     * @return list<array{name: string, tmp_name: string, size: int, error: int}>
     */
    public static function uploads(?array $field): array
    {
        if (!is_array($field) || !isset($field['error'])) {
            return [];
        }
        if (!is_array($field['error'])) {   // a single file
            return (int) $field['error'] === UPLOAD_ERR_NO_FILE ? [] : [['name' => (string) $field['name'], 'tmp_name' => (string) $field['tmp_name'], 'size' => (int) $field['size'], 'error' => (int) $field['error']]];
        }
        $out = [];
        foreach ($field['error'] as $i => $error) {
            if ((int) $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => (string) ($field['name'][$i] ?? ''), 'tmp_name' => (string) ($field['tmp_name'][$i] ?? ''), 'size' => (int) ($field['size'][$i] ?? 0), 'error' => (int) $error];
        }

        return $out;
    }

    /** @return list<array> one record's files, oldest first, each with url and is_pdf */
    public function forOwner(string $owner, int $ownerId): array
    {
        return array_map([self::class, 'decorate'], $this->attachments->forOwner($owner, $ownerId));
    }

    /** @return array<int, list<array>> record id => its files, for a page of records */
    public function forOwners(string $owner, array $ownerIds): array
    {
        return array_map(static fn (array $rows): array => array_map([self::class, 'decorate'], $rows), $this->attachments->forOwners($owner, $ownerIds));
    }

    /** Relative to public/, for <img src> and <a href>. */
    public static function url(string $file): string
    {
        return UPLOADS_URL . '/attachments/' . $file;
    }

    private static function decorate(array $r): array
    {
        $r['url'] = self::url($r['file']);
        $r['is_pdf'] = $r['mime'] === 'application/pdf';

        return $r;
    }
}
