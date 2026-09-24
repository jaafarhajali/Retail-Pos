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

    /** @return string the zip path */
    public function create(): string
    {
        $dir = STORAGE_PATH . '/backups';
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new \RuntimeException('Cannot create the backups folder.');
        }
        $stamp = date('Y-m-d_His');
        $sql = "{$dir}/{$stamp}.sql";
        $cmd = sprintf('"%s" --host=%s --port=%d --user=%s %s --single-transaction --routines --default-character-set=utf8mb4 %s > "%s" 2>&1',
            self::mysqldumpPath(), DB_HOST, DB_PORT, DB_USER, DB_PASS === '' ? '' : '--password=' . escapeshellarg(DB_PASS), DB_NAME, $sql);
        exec($cmd, $out, $rc);
        if ($rc !== 0 || !is_file($sql) || filesize($sql) < 100) {
            @unlink($sql);
            throw new \RuntimeException('mysqldump failed: ' . implode(' ', $out));
        }
        $zipPath = "{$dir}/{$stamp}.zip";
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
        $zip->close();
        unlink($sql);
        $this->prune($dir, 30);

        return $zipPath;
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
