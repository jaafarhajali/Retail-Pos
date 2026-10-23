<?php
/**
 * php bin/backup-service.php [--once] [--interval=60]
 * The backup service (2026-10-23): what the Windows service "<Product> Backup" runs. Once a minute, quietly, it takes
 * the same decision as `bin/backup.php --auto`: the daily backup once the time set on the Backups page has passed and
 * none was made today (the 1st of the month: the monthly one); otherwise it retries a Google Drive upload that failed.
 * Only what it did goes to storage/logs/backup-service.log: a backup, a retry, a failure. A file storage/backup-service.stop ends the loop
 * (the service wrapper writes it on stop). --once runs one decision and exits, for tests and for the task scheduler.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\BackupService;

$opts = getopt('', ['once', 'interval:']);
$interval = max(60, (int) ($opts['interval'] ?? 60));
$stopFile = STORAGE_PATH . '/backup-service.stop';
@unlink($stopFile);
$log = static function (string $line): void {
    @file_put_contents(STORAGE_PATH . '/logs/backup-service.log', date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND);
    echo $line, "\n";
};
$log('service started (daily backup at ' . BackupService::dailyTime() . ', checked every ' . $interval . ' s)');

do {
    try {
        $line = (new BackupService())->serviceTick();
        if (!str_starts_with($line, 'nothing due (') || str_contains($line, 'retry')) {   // a quiet minute is not worth a line
            $log($line);
        }
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
