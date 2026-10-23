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
    (new Setting())->setMany(['backup_copy_dir' => '', 'backup_last_at' => '', 'backup_last_file' => '', 'backup_last_kind' => '', 'backup_last_copy' => 'off', 'backup_last_error' => '', 'backup_daily_time' => '']);
    Settings::flush();
};
$usb = static fn (): string => BackupService::dir() . '/usb';

return [
    '__before' => function () use ($clean): void {
        test_db_reset();
        $clean();
    },

    'a kind names the zip; each kind keeps its own newest files; old hourly zips are cleaned away' => function (): void {
        $dir = BackupService::dir();
        @mkdir($dir, 0777, true);
        // 50 hourly zips from the old schedule, 3 manual: the next backup deletes every hourly one, the manual ones are untouched
        for ($i = 1; $i <= 50; $i++) {
            file_put_contents(sprintf('%s/2026-01-%02d_%02d0000-hourly.zip', $dir, 1 + intdiv($i, 24), $i % 24), 'x');
        }
        foreach (['2026-01-01_010000.zip', '2026-01-02_010000.zip', '2026-01-03_010000.zip'] as $m) {
            file_put_contents("{$dir}/{$m}", 'x');
        }
        $svc = new BackupService();
        $file = $svc->create('daily');
        assert_true(str_ends_with($file, '-daily.zip'), $file);
        assert_same('daily', BackupService::kindOf($file));
        assert_same(0, count(glob("{$dir}/*-hourly.zip") ?: []), 'hourly backups ended: the old zips are gone');
        $e = assert_throws(RuntimeException::class, fn () => $svc->create('hourly'));
        assert_contains('Unknown backup kind', $e->getMessage());
        assert_same(3, count(array_filter(glob("{$dir}/*.zip"), static fn (string $f): bool => BackupService::kindOf($f) === 'manual')));
        assert_same('off', $svc->lastCopy, 'no USB folder set');
        $status = BackupService::status();
        assert_same(['daily', basename($file)], [$status['last_kind'], $status['last_file']]);
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
        $file = $svc->create('manual');
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

    'one daily task at the set time, hidden, run after a missed start; the old tasks are named for deletion' => function (): void {
        (new Setting())->setMany(['backup_daily_time' => '21:30']);
        Settings::flush();
        $cmds = BackupService::taskCommands('C:\xampp\php\php.exe', 'C:\xampp\htdocs\Retail POS\bin\backup.php');
        assert_same(2, count($cmds));
        assert_contains('/SC DAILY /ST 21:30 /TN "Codexy ERP backup"', $cmds[0]);
        assert_contains('wscript.exe //B', $cmds[0], 'no console window');
        assert_contains('backup-hidden.vbs', $cmds[0]);
        assert_contains('Retail POS\bin\backup.php', $cmds[0]);
        assert_contains('-StartWhenAvailable', $cmds[1], 'a missed night is caught up');
        assert_contains('/ST 03:15', BackupService::taskCommands('php.exe', 'backup.php', '03:15')[0]);
        assert_same(['Retail POS backup hourly', 'Retail POS backup daily', 'Retail POS backup'], BackupService::oldTaskNames());
        assert_true(is_file(BASE_PATH . '/bin/backup-hidden.vbs'));
    },
    'the automatic run makes the daily backup once the set time has passed, once a day, monthly on the 1st' => function (): void {
        (new Setting())->setMany(['backup_daily_time' => '21:30']);
        Settings::flush();
        assert_same('21:30', BackupService::dailyTime());
        $day = (int) strtotime('2026-10-15 00:00:00');
        assert_same(null, BackupService::autoKind($day + 21 * 3600), 'not due at 21:00');
        assert_same('daily', BackupService::autoKind($day + 21 * 3600 + 31 * 60), 'due at 21:31');
        assert_same('daily', BackupService::autoKind($day + 23 * 3600 + 59 * 60), 'still due late at night when none was made');
        @mkdir(BackupService::dir(), 0777, true);
        file_put_contents(BackupService::dir() . '/2026-10-15_213001-daily.zip', 'x');
        assert_same(null, BackupService::autoKind($day + 22 * 3600), 'done for today');
        $first = (int) strtotime('2026-11-01 22:00:00');
        assert_same('monthly', BackupService::autoKind($first), 'the 1st gives the monthly one');
        (new Setting())->setMany(['backup_daily_time' => '']);
        Settings::flush();
        assert_same('02:00', BackupService::dailyTime(), 'empty falls back to the default');
        $e = assert_throws(RuntimeException::class, fn () => (new BackupService())->setDailyTime('25:00'));
        assert_contains('HH:MM', $e->getMessage());
    },
    'the Backups page shows the status and saves the USB folder; the dashboard shows the warning' => function () use ($usb): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('backup')->body;
        assert_contains('No backup has run yet', $page);
        assert_contains('name="backup_copy_dir"', $page);
        assert_contains('install-backup-tasks.php', $page);
        assert_contains('name="backup_daily_time"', $page);
        assert_same(302, $client->post('backup/schedule', ['backup_daily_time' => '21:30'])->status);
        Settings::flush();   // the page wrote it in the server process; this process has a cache
        assert_same('21:30', Settings::get('backup_daily_time'));
        assert_contains('The daily backup is made at 21:30', $client->get('backup')->body);
        $client->post('backup/schedule', ['backup_daily_time' => '9pm']);
        assert_contains('Give the time as HH:MM', $client->get('backup')->body);
        Settings::flush();
        assert_same('21:30', Settings::get('backup_daily_time'), 'a bad time changes nothing');
        assert_same(302, $client->post('backup/settings', ['backup_copy_dir' => $usb()])->status);
        Settings::flush();
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
        // the bin on a file: it goes from this PC, nothing else
        $name = basename((string) (glob(BackupService::dir() . '/*.zip') ?: [''])[0]);
        assert_contains('name="file" value="' . $name . '"', $page, 'a bin on the row');
        assert_same(302, $client->post('backup/delete', ['file' => $name])->status);
        assert_true(!is_file(BackupService::dir() . '/' . $name), 'the local zip is gone');
        assert_true(is_file($usb() . '/' . $name), 'the copy on the stick stays');
        assert_contains($name . ' was deleted from this PC.', $client->get('backup')->body);
        $client->post('backup/delete', ['file' => '../../config/config.php']);
        assert_contains('not in the folder any more', $client->get('backup')->body);
        assert_true(is_file(BASE_PATH . '/config/config.php'));
        assert_contains('Daily at 21:30, a copy on the stick', $page);
        assert_contains('USB copy OK', $client->get('dashboard')->body);
    },
];
