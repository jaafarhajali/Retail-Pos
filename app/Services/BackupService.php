<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;
use App\Models\Setting;

/**
 * One zip per backup: the SQL dump + public/uploads (owner decision 2026-09-24). Used by the page and bin/backup.php.
 * Since 2026-10-10 a backup has a kind (hourly, daily, monthly, manual), each kind keeps its own number of zips, every
 * zip is copied to the USB folder set on the Backups page, and the last result is kept in the settings for the dashboard.
 */
final class BackupService
{
    /** How many zips of each kind stay, here and on the USB stick: 2 days of hours, a month of days, 2 years of months. */
    public const KEEP = ['hourly' => 48, 'daily' => 30, 'monthly' => 24, 'manual' => 20];

    /** How the last backup went: 'ok', 'off' (no USB folder set) or the error text. */
    public string $lastCopy = 'off';

    /** The test suite backs up its own database into its own folder, so its zips never crowd out the real ones. */
    public static function dir(): string
    {
        return STORAGE_PATH . '/backups' . (APP_ENV === 'test' ? '/test' : '');
    }

    public static function mysqldumpPath(): string
    {
        foreach (['C:/xampp/mysql/bin/mysqldump.exe', '/usr/bin/mysqldump'] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        return 'mysqldump';
    }

    /**
     * What stops a backup on this machine, in words the owner can act on. Empty when all is well.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];
        if (!class_exists(\ZipArchive::class)) {
            $ini = php_ini_loaded_file() ?: 'php.ini';
            $problems[] = "PHP's zip extension is off, so no backup can be made. In {$ini} remove the \";\" before \"extension=zip\", then restart Apache.";
        }
        $dump = self::mysqldumpPath();
        if ($dump === 'mysqldump' && PHP_OS_FAMILY === 'Windows') {
            $problems[] = 'mysqldump.exe was not found in C:/xampp/mysql/bin.';
        }

        return $problems;
    }

    /** @return string the zip path */
    public function create(string $kind = 'manual'): string
    {
        if (!isset(self::KEEP[$kind])) {
            throw new \RuntimeException('Unknown backup kind: ' . $kind);
        }
        if (($problems = self::problems()) !== []) {
            throw new \RuntimeException($problems[0]);
        }
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new \RuntimeException('Cannot create the backups folder.');
        }
        $suffix = $kind === 'manual' ? '' : '-' . $kind;
        $stamp = date('Y-m-d_His');
        for ($n = 2; is_file("{$dir}/{$stamp}{$suffix}.zip"); $n++) {   // two backups in the same second get their own names
            $stamp = date('Y-m-d_His') . '-' . $n;
        }
        $stamp .= $suffix;
        $sql = "{$dir}/{$stamp}.sql";
        $err = "{$dir}/{$stamp}.err";
        // What mysqldump complains about goes to its own file: mixed into the dump it would break the restore.
        $cmd = sprintf('"%s" --host=%s --port=%d --user=%s %s --single-transaction --routines --default-character-set=utf8mb4 %s > "%s" 2> "%s"',
            self::mysqldumpPath(), DB_HOST, DB_PORT, DB_USER, DB_PASS === '' ? '' : '--password=' . escapeshellarg(DB_PASS), DB_NAME, $sql, $err);
        exec($cmd, $out, $rc);
        $said = is_file($err) ? trim((string) file_get_contents($err)) : '';
        @unlink($err);
        $complete = is_file($sql) && filesize($sql) >= 100 && str_contains((string) file_get_contents($sql, false, null, max(0, filesize($sql) - 200)), 'Dump completed');
        if ($rc !== 0 || !$complete) {
            @unlink($sql);
            throw new \RuntimeException('mysqldump failed' . ($said === '' ? '.' : ': ' . $said));
        }
        $zipPath = "{$dir}/{$stamp}.zip";
        try {
            $this->zip($zipPath, $sql, $stamp);
        } catch (\Throwable $e) {
            $this->remember(['backup_last_error' => date('Y-m-d H:i') . ' ' . $e->getMessage()]);
            throw $e;
        } finally {
            @unlink($sql);   // the plain dump never stays behind, whatever happened
        }
        $this->pruneAll($dir);
        $this->lastCopy = $this->copyToUsb($zipPath);
        $this->remember(['backup_last_at' => date('Y-m-d H:i:s'), 'backup_last_file' => basename($zipPath), 'backup_last_kind' => $kind, 'backup_last_copy' => $this->lastCopy, 'backup_last_error' => '']);

        return $zipPath;
    }

    /**
     * The zip goes to the USB stick (or any folder) set on the Backups page, and old zips are pruned there too.
     * A missing stick never stops the backup: the local zip exists, the dashboard turns amber.
     */
    private function copyToUsb(string $zipPath): string
    {
        $dir = rtrim(str_replace('\\', '/', Settings::get('backup_copy_dir', '')), '/');
        if ($dir === '') {
            return 'off';
        }
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            return 'The USB folder ' . $dir . ' is not there. Is the stick plugged in?';
        }
        $target = $dir . '/' . basename($zipPath);
        if (!@copy($zipPath, $target) || filesize($target) !== filesize($zipPath)) {
            @unlink($target);
            return 'Could not write to ' . $dir . '. Is the stick full or read-only?';
        }
        $this->pruneAll($dir);

        return 'ok';
    }

    private function remember(array $values): void
    {
        (new Setting())->setMany($values);
        Settings::flush();
    }

    /** Saves the folder the zips are copied to ('' switches the copy off); it must exist or be creatable. */
    public function setCopyDir(string $dir): void
    {
        $dir = trim(str_replace('\\', '/', $dir));
        if ($dir !== '' && !is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new \RuntimeException('That folder does not exist and cannot be created: ' . $dir . '. Plug the stick in and check the drive letter.');
        }
        $this->remember(['backup_copy_dir' => $dir]);
    }

    /**
     * For the dashboard and the Backups page: when the last backup ran, whether the USB copy worked, and what is wrong.
     *
     * @return array{last_at: string, last_file: string, last_kind: string, copy: string, copy_dir: string, error: string, age_hours: float|null, warnings: list<string>}
     */
    public static function status(): array
    {
        $lastAt = Settings::get('backup_last_at', '');
        $age = $lastAt === '' ? null : (time() - (int) strtotime($lastAt)) / 3600;
        $copy = Settings::get('backup_last_copy', 'off');
        $copyDir = Settings::get('backup_copy_dir', '');
        $error = Settings::get('backup_last_error', '');
        $warnings = [];
        if ($age === null) {
            $warnings[] = 'No automatic backup has run yet. Run bin/install-backup-tasks.php once on the server.';
        } elseif ($age > 26) {
            $warnings[] = 'The last backup is ' . round($age / 24, 1) . ' days old. The scheduled task is not running.';
        }
        if ($error !== '') {
            $warnings[] = 'The last backup failed: ' . $error;
        }
        if ($copyDir === '') {
            $warnings[] = 'No USB folder is set: the backups stay on this PC only.';
        } elseif ($copy !== 'ok' && $copy !== 'off') {
            $warnings[] = 'USB copy failed: ' . $copy;
        } elseif (!is_dir($copyDir)) {
            $warnings[] = 'The USB folder ' . $copyDir . ' is not there now. Is the stick plugged in?';
        }

        return ['last_at' => $lastAt, 'last_file' => Settings::get('backup_last_file', ''), 'last_kind' => Settings::get('backup_last_kind', ''), 'copy' => $copy,
                'copy_dir' => $copyDir, 'error' => $error, 'age_hours' => $age, 'warnings' => $warnings];
    }

    /**
     * The two Windows scheduled tasks, as schtasks commands (bin/install-backup-tasks.php runs them).
     *
     * @return array<string, string> task name => command
     */
    public static function taskCommands(string $php, string $script): array
    {
        $run = static fn (string $kind): string => '"\\"' . $php . '\\" \\"' . $script . '\\" --kind=' . $kind . '"';

        return [
            'Retail POS backup hourly' => 'schtasks /Create /F /SC HOURLY /MO 1 /ST 08:00 /ET 23:59 /TN "Retail POS backup hourly" /TR ' . $run('hourly'),
            'Retail POS backup daily'  => 'schtasks /Create /F /SC DAILY /ST 02:00 /TN "Retail POS backup daily" /TR ' . $run('daily'),
        ];
    }

    /** The kind a zip's name says: hourly, daily, monthly, or manual when it has no suffix. */
    public static function kindOf(string $file): string
    {
        return preg_match('/-(hourly|daily|monthly)\.zip$/', $file, $m) ? $m[1] : 'manual';
    }

    private function zip(string $zipPath, string $sql, string $stamp): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Cannot create the zip file.');
        }
        $zip->addFile($sql, "{$stamp}.sql");
        $uploads = BASE_PATH . '/public/uploads';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = str_replace('\\', '/', substr((string) $file, strlen($uploads) + 1));
            if (str_starts_with($rel, 'test/')) {
                continue;
            }
            $zip->addFile((string) $file, 'uploads/' . $rel);
        }
        if (!$zip->close()) {
            @unlink($zipPath);
            throw new \RuntimeException('The zip file could not be written.');
        }
    }

    public function list(): array
    {
        $files = glob(self::dir() . '/*.zip') ?: [];
        rsort($files);

        return array_map(static fn (string $f): array => ['name' => basename($f), 'kind' => self::kindOf($f), 'size' => filesize($f), 'at' => date('d/m/Y H:i', filemtime($f) ?: 0)], $files);
    }

    /** Each kind keeps its own newest zips (KEEP); the others are deleted. Newest first by name, which is the date. */
    private function pruneAll(string $dir): void
    {
        $byKind = [];
        foreach (glob("{$dir}/*.zip") ?: [] as $file) {
            $byKind[self::kindOf($file)][] = $file;
        }
        foreach ($byKind as $kind => $files) {
            rsort($files);
            foreach (array_slice($files, self::KEEP[$kind]) as $old) {
                @unlink($old);
            }
        }
    }
}
