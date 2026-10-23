<?php
/**
 * php bin/install-backup-tasks.php [--print]
 * Creates the one Windows scheduled task that backs the shop up by itself (run it once on the server, as the Windows
 * user who is normally signed in): "Codexy ERP backup" runs bin/backup.php --auto once a day at the time set on the
 * Backups page, hidden, and as soon as possible after a start the PC missed. The tasks of the old schedules (hourly,
 * daily at a fixed hour, every 30 minutes) are deleted. --print only shows the commands. Running it again replaces the task.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\BackupService;

$php = str_replace('/', '\\', PHP_BINARY);
$script = str_replace('/', '\\', BASE_PATH . '/bin/backup.php');
$print = in_array('--print', $argv, true);
if (PHP_OS_FAMILY !== 'Windows' && !$print) {
    fwrite(STDERR, "This creates a Windows scheduled task; on another system run bin/backup.php --auto from cron every 30 minutes.\n");
    exit(1);
}
foreach (BackupService::oldTaskNames() as $old) {
    $cmd = 'schtasks /Delete /F /TN "' . $old . '"';
    echo $cmd, "\n";
    if (!$print) {
        exec($cmd . ' 2>&1', $out, $rc);
        echo '  ', $rc === 0 ? "deleted: {$old}" : 'was not there', "\n";
    }
}
foreach (BackupService::taskCommands($php, $script) as $cmd) {
    echo $cmd, "\n";
    if ($print) {
        continue;
    }
    exec($cmd . ' 2>&1', $out, $rc);
    echo '  ', $rc === 0 ? 'done' : 'FAILED: ' . implode(' ', $out), "\n";
    if ($rc !== 0) {
        exit(1);
    }
}
if (!$print) {
    echo 'Done. The daily backup is made at ', BackupService::dailyTime(), " (change it on the Backups page); the Backups page shows the last run.\n";
}