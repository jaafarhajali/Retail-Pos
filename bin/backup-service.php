<?php
/**
 * php bin/backup-service.php [--once] [--interval=1800]
 * The backup service (2026-10-23): what the Windows service "<Product> Backup" runs. Every 30 minutes it takes the
 * same decision as `bin/backup.php --auto`: the daily backup once the time set on the Backups page has passed and none
 * was made today (the 1st of the month: the monthly one); otherwise it retries a Google Drive upload that failed.
 * One line per run goes to storage/logs/backup-service.log. A file storage/backup-service.stop ends the loop
 * (the service wrapper writes it on stop). --once runs one decision and exits, for tests and for the task scheduler.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\BackupService;

$opts = getopt('', ['once', 'interval:']);
$interval = max(60, (int) ($opts['interval'] ?? 1800));
$stopFile = STORAGE_PATH . '/backup-service.stop';
@unlink($stopFile);
$log = static function (string $line): void {
    @file_put_contents(STORAGE_PATH . '/logs/backup-service.log', date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND);
    echo $line, "\n";
};
$log('service started (every ' . $interval . ' s, daily backup at ' . BackupService::dailyTime() . ')');

do {
    try {
        $log((new BackupService())->serviceTick());
    } catch (Throwable $e) {
        $log('failed: ' . $e->getMessage());
    }
    if (isset($opts['once'])) {
        break;
    }
    // sleep in short steps so a stop is noticed within a minute
    for ($waited = 0; $waited < $interval; $waited += 20) {
        if (is_file($stopFile)) {
            $log('service stopped');
            @unlink($stopFile);
            exit(0);
        }
        sleep(20);
    }
} while (true);
