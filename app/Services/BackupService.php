<?php
declare(strict_types=1);

namespace App\Services;

/** One zip per backup: the SQL dump + public/uploads (owner decision 2026-09-24). Used by the page and bin/backup.php. */
final class BackupService
{
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
    public function create(): string
    {
        if (($problems = self::problems()) !== []) {
            throw new \RuntimeException($problems[0]);
        }
        $dir = STORAGE_PATH . '/backups';
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new \RuntimeException('Cannot create the backups folder.');
        }
        $stamp = date('Y-m-d_His');
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
        } finally {
            @unlink($sql);   // the plain dump never stays behind, whatever happened
        }
        $this->prune($dir, 30);

        return $zipPath;
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
        $files = glob(STORAGE_PATH . '/backups/*.zip') ?: [];
        rsort($files);

        return array_map(static fn (string $f): array => ['name' => basename($f), 'size' => filesize($f), 'at' => date('d/m/Y H:i', filemtime($f) ?: 0)], $files);
    }

    private function prune(string $dir, int $keep): void
    {
        $files = glob("{$dir}/*.zip") ?: [];
        rsort($files);
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
