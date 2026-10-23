<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * "Check this installation" (2026-10-23): what a shop's PC needs for the program to work, each line green, amber or red
 * in plain words, so a support call starts with a screenshot of this page instead of guesses.
 */
final class InstallCheckService
{
    /** @return list<array{what: string, state: string, detail: string}> state = ok | warn | fail */
    public function run(): array
    {
        $rows = [];
        $rows[] = ['what' => 'Program version', 'state' => 'ok', 'detail' => APP_VERSION . ' · ' . (APP_ENV === 'production' ? 'production mode' : APP_ENV . ' mode')];
        $rows[] = ['what' => 'PHP', 'state' => version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'fail', 'detail' => PHP_VERSION . ' · ' . (php_ini_loaded_file() ?: 'no php.ini')];
        if (PHP_OS_FAMILY === 'Windows') {
            $sys = getenv('SystemRoot') . '/System32';
            $lost = array_filter(['vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll'], static fn (string $d): bool => !is_file($sys . '/' . $d));
            $rows[] = ['what' => 'Visual C++ runtime', 'state' => $lost === [] ? 'ok' : 'fail', 'detail' => $lost === [] ? 'installed' : 'missing ' . implode(', ', $lost) . ': run the setup again, it installs it'];
        }
        foreach (['pdo_mysql' => 'database driver', 'gd' => 'product images and the logo', 'zip' => 'backups', 'mbstring' => 'Arabic text', 'curl' => 'Google Drive'] as $ext => $why) {
            $rows[] = ['what' => "PHP extension {$ext}", 'state' => extension_loaded($ext) ? 'ok' : ($ext === 'curl' ? 'warn' : 'fail'), 'detail' => extension_loaded($ext) ? 'on' : "off: needed for {$why}"];
        }
        foreach (['storage' => STORAGE_PATH, 'storage/backups' => BackupService::dir(), 'public/uploads' => UPLOADS_PATH, 'storage/sessions' => STORAGE_PATH . '/sessions', 'storage/logs' => STORAGE_PATH . '/logs'] as $label => $dir) {
            $ok = is_dir($dir) && is_writable($dir);
            $rows[] = ['what' => "Folder {$label}", 'state' => $ok ? 'ok' : 'fail', 'detail' => $ok ? 'writable' : (is_dir($dir) ? 'not writable' : 'missing')];
        }
        try {
            $pdo = Database::pdo();
            $rows[] = ['what' => 'Database', 'state' => 'ok', 'detail' => DB_NAME . ' on ' . DB_HOST . ':' . DB_PORT . ' · MariaDB ' . (string) $pdo->query('SELECT VERSION()')->fetchColumn()
                . ' · ' . (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() . ' migrations'];
        } catch (\Throwable $e) {
            $rows[] = ['what' => 'Database', 'state' => 'fail', 'detail' => 'cannot connect: ' . $e->getMessage()];
        }
        $dump = BackupService::mysqldumpPath();
        $rows[] = ['what' => 'Backup tools', 'state' => is_file($dump) && is_file(BackupService::mysqlPath()) ? 'ok' : 'fail', 'detail' => is_file($dump) ? $dump : 'mysqldump not found'];
        $status = BackupService::status();
        $rows[] = ['what' => 'Backups', 'state' => $status['warnings'] === [] ? 'ok' : 'warn',
                   'detail' => ($status['last_at'] === '' ? 'none yet' : 'last ' . $status['last_at'] . ' (' . $status['last_kind'] . ')') . ' · daily at ' . $status['daily_time'] . ($status['warnings'] === [] ? '' : ' · ' . implode(' ', $status['warnings']))];
        $free = @disk_free_space(BASE_PATH);
        if ($free !== false) {
            $gb = $free / 1024 ** 3;
            $rows[] = ['what' => 'Disk space', 'state' => $gb < 2 ? 'fail' : ($gb < 10 ? 'warn' : 'ok'), 'detail' => number_format($gb, 1) . ' GB free on the program\'s drive'];
        }
        foreach ($this->services() as $name) {
            [$state, $detail] = $this->serviceState($name);
            $rows[] = ['what' => 'Windows service ' . $name, 'state' => $state, 'detail' => $detail];
        }
        if (BackupService::runner() === 'task') {
            $name = BackupService::TASK_NAME;
            $rows[] = ['what' => 'Backup task', 'state' => $this->taskExists($name) ? 'ok' : 'warn',
                       'detail' => $this->taskExists($name) ? 'the Windows task "' . $name . '" exists, daily at ' . BackupService::dailyTime() : 'no Windows task: run bin/install-backup-tasks.php once on the server'];
        }

        return $rows;
    }

    /** The services the installer set up, from the settings it wrote; empty on a XAMPP install. @return list<string> */
    public function services(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', Settings::get('install_services', '')))));
    }

    /** @return array{0: string, 1: string} */
    private function serviceState(string $name): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return ['warn', 'not a Windows PC'];
        }
        exec('sc query "' . str_replace('"', '', $name) . '" 2>&1', $out, $rc);
        $text = implode(' ', $out);
        if ($rc !== 0) {
            return ['fail', 'missing: run the setup again'];
        }
        if (str_contains($text, 'RUNNING')) {
            return ['ok', 'running'];
        }

        return ['fail', 'stopped: start it in Windows Services or run the setup again'];
    }

    private function taskExists(string $name): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }
        exec('schtasks /Query /TN "' . $name . '" 2>&1', $out, $rc);

        return $rc === 0;
    }
}
