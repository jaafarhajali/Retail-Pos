<?php
/**
 * php bin/install-backup-tasks.php [--print]
 * Creates the two Windows scheduled tasks that back the shop up by themselves (run it once on the server, as the
 * Windows user who is normally signed in):
 *   "Retail POS backup hourly"  every hour from 08:00 to 23:59  →  bin/backup.php --kind=hourly
 *   "Retail POS backup daily"   every day at 02:00              →  bin/backup.php --kind=daily
 * --print only shows the commands. Running it again replaces the tasks (/F).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$php = str_replace('/', '\\', PHP_BINARY);
$script = str_replace('/', '\\', BASE_PATH . '/bin/backup.php');
$commands = App\Services\BackupService::taskCommands($php, $script);
$print = in_array('--print', $argv, true);
if (PHP_OS_FAMILY !== 'Windows' && !$print) {
    fwrite(STDERR, "This creates Windows scheduled tasks; on another system schedule bin/backup.php with cron.\n");
    exit(1);
}
foreach ($commands as $name => $cmd) {
    echo $cmd, "\n";
    if ($print) {
        continue;
    }
    exec($cmd . ' 2>&1', $out, $rc);
    echo '  ', $rc === 0 ? "created: {$name}" : 'FAILED: ' . implode(' ', $out), "\n";
    if ($rc !== 0) {
        exit(1);
    }
}
if (!$print) {
    echo "Done. The Backups page shows the last run; plug the USB stick in and set its folder there.\n";
}