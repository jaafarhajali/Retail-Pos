<?php
/**
 * php bin/backup.php --auto            what the Windows task runs every 30 minutes: makes the daily backup once the time
 *                                      set on the Backups page has passed and none was made today (the 1st: the monthly one)
 * php bin/backup.php [--kind=daily|monthly|manual]   a backup right now, of that kind (default manual)
 *
 * A backup = storage/backups/<date>-<kind>.zip (SQL dump + public/uploads), copied to the USB folder, uploaded to Google
 * Drive once connected; the newest of each kind are kept (30 daily, 24 monthly, 20 manual, 10 pre-restore).
 * Hourly backups ended 2026-10-23; their old zips are deleted on the next run.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\BackupService;

$opts = getopt('', ['kind:', 'auto']);
if (isset($opts['auto'])) {
    $kind = BackupService::autoKind();
    if ($kind === null) {
        echo 'Nothing to do: the daily backup (', BackupService::dailyTime(), ') is done for today or not due yet.', "\n";
        $retry = (new BackupService())->retryDrive();   // a night upload that failed (no internet) goes again now
        if ($retry !== 'skipped' && $retry !== 'off') {
            echo 'Google Drive retry: ', $retry, "\n";
        }
        exit(0);
    }
    echo "Daily backup due (", BackupService::dailyTime(), "): making the {$kind} one.\n";
} else {
    $kind = (string) ($opts['kind'] ?? 'manual');
    if ($kind === 'daily' && date('j') === '1') {
        $kind = 'monthly';
    }
}

try {
    $svc = new BackupService();
    $file = $svc->create($kind);
    echo 'Backup written: ', $file, "\n";
    echo 'USB copy: ', $svc->lastCopy, "\n";
    echo 'Google Drive: ', $svc->lastDrive, "\n";
    $fine = static fn (string $r): bool => in_array($r, ['ok', 'off', 'skipped'], true);
    if (!$fine($svc->lastCopy)) {
        @file_put_contents(STORAGE_PATH . '/logs/backup-failed.log', date('Y-m-d H:i:s') . ' USB copy: ' . $svc->lastCopy . "\n", FILE_APPEND);
        exit(2);
    }
    if (!$fine($svc->lastDrive)) {
        @file_put_contents(STORAGE_PATH . '/logs/backup-failed.log', date('Y-m-d H:i:s') . ' Google Drive: ' . $svc->lastDrive . "\n", FILE_APPEND);
        exit(3);
    }
} catch (Throwable $e) {
    // The task has no screen: leave a trace the Backups page and the owner can find.
    @file_put_contents(STORAGE_PATH . '/logs/backup-failed.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}