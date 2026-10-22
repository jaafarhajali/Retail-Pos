<?php
/**
 * php bin/backup.php [--kind=hourly|daily|monthly|manual]
 * Writes storage/backups/<date>-<kind>.zip = SQL dump + public/uploads, copies it to the USB folder set on the
 * Backups page, uploads the daily and monthly ones to Google Drive once it is connected there, and keeps the newest
 * of each kind (48 hourly, 30 daily, 24 monthly, 20 manual).
 * The scheduled tasks (bin/install-backup-tasks.php) run it hourly in opening hours and daily at 02:00;
 * the daily run on the 1st of the month is kept as that month's backup.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$opts = getopt('', ['kind:']);
$kind = (string) ($opts['kind'] ?? 'manual');
if ($kind === 'daily' && date('j') === '1') {
    $kind = 'monthly';
}

try {
    $svc = new App\Services\BackupService();
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
    // The nightly task has no screen: leave a trace the Backups page and the owner can find.
    @file_put_contents(STORAGE_PATH . '/logs/backup-failed.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
