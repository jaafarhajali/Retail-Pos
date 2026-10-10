<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Settings;
use App\Models\Setting;
use App\Services\BackupService;

/**
 * Owner, 2026-10-10: backups run by themselves (hourly, daily, monthly), keep the newest of each kind, and every
 * zip is copied to the USB stick. A missing stick never stops the backup; the dashboard says so.
 */
$clean = static function (): void {
    foreach (glob(BackupService::dir() . '/*.zip') ?: [] as $f) {
        @unlink($f);
    }
    $usb = BackupService::dir() . '/usb';
    foreach (glob($usb . '/*.zip') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($usb);
    (new Setting())->setMany(['backup_copy_dir' => '', 'backup_last_at' => '', 'backup_last_file' => '', 'backup_last_kind' => '', 'backup_last_copy' => 'off', 'backup_last_error' => '']);
    Settings::flush();
};
$usb = static fn (): string => BackupService::dir() . '/usb';

return [
    '__before' => function () use ($clean): void {
        test_db_reset();
        $clean();
    },

    'a kind names the zip; each kind keeps its own newest files' => function (): void {
        $dir = BackupService::dir();
        @mkdir($dir, 0777, true);
        // 50 old hourly zips, 3 manual: only the newest 48 hourly stay, the manual ones are untouched
        for ($i = 1; $i <= 50; $i++) {
            file_put_contents(sprintf('%s/2026-01-%02d_%02d0000-hourly.zip', $dir, 1 + intdiv($i, 24), $i % 24), 'x');
        }
        foreach (['2026-01-01_010000.zip', '2026-01-02_010000.zip', '2026-01-03_010000.zip'] as $m) {
            file_put_contents("{$dir}/{$m}", 'x');
        }
        $svc = new BackupService();
        $file = $svc->create('hourly');
        assert_true(str_ends_with($file, '-hourly.zip'), $file);
        assert_same('hourly', BackupService::kindOf($file));
        assert_same(48, count(glob("{$dir}/*-hourly.zip")), 'the newest 48 hourly zips stay');
        assert_same(3, count(array_filter(glob("{$dir}/*.zip"), static fn (string $f): bool => BackupService::kindOf($f) === 'manual')));
        assert_same('off', $svc->lastCopy, 'no USB folder set');
        $status = BackupService::status();
        assert_same(['hourly', basename($file)], [$status['last_kind'], $status['last_file']]);
        assert_contains('No USB folder is set', implode(' ', $status['warnings']));
        $e = assert_throws(RuntimeException::class, fn () => $svc->create('weekly'));
        assert_contains('Unknown backup kind', $e->getMessage());
    },

    'the zip is copied to the USB folder, and a missing stick is reported without stopping the backup' => function () use ($usb): void {
        $svc = new BackupService();
        $svc->setCopyDir($usb());
        $file = $svc->create('daily');
        assert_same('ok', $svc->lastCopy);
        assert_true(is_file($usb() . '/' . basename($file)), 'the copy is on the stick');
        assert_same(filesize($file), filesize($usb() . '/' . basename($file)));
        assert_same([], BackupService::status()['warnings'], 'all good: fresh backup, USB copy OK');
        // the stick is pulled out: the backup still happens, the status turns amber
        $gone = 'Q:/no-such-stick/RetailPOS';
        (new Setting())->setMany(['backup_copy_dir' => $gone]);
        Settings::flush();
        $file = $svc->create('hourly');
        assert_true(is_file($file));
        assert_contains('Is the stick plugged in?', $svc->lastCopy);
        assert_contains('USB copy failed', implode(' ', BackupService::status()['warnings']));
        $e = assert_throws(RuntimeException::class, fn () => $svc->setCopyDir($gone));
        assert_contains('does not exist', $e->getMessage());
    },

    'the status knows an old backup' => function (): void {
        (new Setting())->setMany(['backup_last_at' => date('Y-m-d H:i:s', time() - 3 * 86400), 'backup_last_copy' => 'ok', 'backup_copy_dir' => BackupService::dir()]);
        Settings::flush();
        $s = BackupService::status();
        assert_true($s['age_hours'] > 71);
        assert_contains('3 days old', implode(' ', $s['warnings']));
    },

    'the scheduled tasks are two schtasks commands with the right times and kinds' => function (): void {
        $cmds = BackupService::taskCommands('C:\\xampp\\php\\php.exe', 'C:\\xampp\\htdocs\\Retail POS\\bin\\backup.php');
        assert_same(['Retail POS backup hourly', 'Retail POS backup daily'], array_keys($cmds));
        assert_contains('/SC HOURLY /MO 1 /ST 08:00 /ET 23:59', $cmds['Retail POS backup hourly']);
        assert_contains('--kind=hourly', $cmds['Retail POS backup hourly']);
        assert_contains('/SC DAILY /ST 02:00', $cmds['Retail POS backup daily']);
        assert_contains('--kind=daily', $cmds['Retail POS backup daily']);
        assert_contains('\\"C:\\xampp\\htdocs\\Retail POS\\bin\\backup.php\\" --kind=daily"', $cmds['Retail POS backup daily'], 'the path with its space is quoted inside /TR');
    },

    'the Backups page shows the status and saves the USB folder; the dashboard shows the warning' => function () use ($usb): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('backup')->body;
        assert_contains('No backup has run yet', $page);
        assert_contains('name="backup_copy_dir"', $page);
        assert_contains('install-backup-tasks.php', $page);
        assert_same(302, $client->post('backup/settings', ['backup_copy_dir' => $usb()])->status);
        assert_same(str_replace('\\', '/', $usb()), Settings::get('backup_copy_dir'));
        assert_contains('is now copied to', $client->get('backup')->body);
        $client->post('backup/settings', ['backup_copy_dir' => 'Q:/no-such-stick']);
        assert_contains('does not exist', $client->get('backup')->body);
        $dash = $client->get('dashboard')->body;
        assert_contains('No automatic backup has run yet', $dash);
        assert_same(302, $client->post('backup/run', [])->status);
        $page = $client->get('backup')->body;
        assert_contains('copied to the USB folder', $page);
        assert_contains('<td>manual</td>', $page);
        assert_contains('USB copy OK', $client->get('dashboard')->body);
    },
];
