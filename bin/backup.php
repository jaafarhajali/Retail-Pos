<?php
/**
 * php bin/backup.php — daily backup (schedule it in Windows Task Scheduler).
 * Writes storage/backups/<date>.zip = SQL dump + public/uploads; keeps the last 30.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $file = (new App\Services\BackupService())->create();
    echo 'Backup written: ', $file, "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
