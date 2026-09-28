<?php
/**
 * php bin/restore-drill.php
 *
 * Proves that a backup can be restored: takes a backup, restores it into its own database
 * (<name>_restore_drill), compares it with the live one table by table and figure by figure,
 * compares the uploaded files, then drops the drill database.
 *
 * The live database is only read. The backup zip stays in storage/backups.
 * Run it after installing, and again every month or after a Windows / XAMPP update.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\BackupService;

$say = static fn (string $line) => print($line . "\n");
$fail = static function (string $why): never {
    fwrite(STDERR, "FAILED: {$why}\n");
    exit(1);
};

foreach (BackupService::problems() as $problem) {
    $fail($problem);
}
$mysql = dirname(BackupService::mysqldumpPath()) . '/mysql' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
if (!is_file($mysql)) {
    $fail("The mysql client was not found next to mysqldump ({$mysql}).");
}

$say('1. Backup');
try {
    $zipPath = (new BackupService())->create();
} catch (\Throwable $e) {
    $fail($e->getMessage());
}
$say('   ' . basename($zipPath) . ', ' . number_format(filesize($zipPath) / 1024) . ' KB');

$say('2. Unpack');
$work = STORAGE_PATH . '/backups/drill-' . bin2hex(random_bytes(4));
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true || !mkdir($work, 0777, true) || !$zip->extractTo($work)) {
    $fail('The zip could not be unpacked.');
}
$say("   {$zip->numFiles} files");
$zip->close();
$dumps = glob($work . '/*.sql') ?: [];
if (count($dumps) !== 1) {
    $fail('The zip does not hold exactly one .sql file.');
}

$cleanup = static function () use (&$pdo, &$drill, $work): void {
    if (isset($pdo, $drill)) {
        $pdo->exec("DROP DATABASE IF EXISTS `{$drill}`");
    }
    if (is_dir($work)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
        }
        rmdir($work);
    }
};

$live = DB_NAME;
$drill = DB_NAME . '_restore_drill';
$pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$say("3. Restore into {$drill}");
$pdo->exec("DROP DATABASE IF EXISTS `{$drill}`");
$pdo->exec("CREATE DATABASE `{$drill}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cmd = sprintf('"%s" --host=%s --port=%d --user=%s %s --default-character-set=utf8mb4 %s < "%s" 2>&1',
    $mysql, DB_HOST, DB_PORT, DB_USER, DB_PASS === '' ? '' : '--password=' . escapeshellarg(DB_PASS), $drill, $dumps[0]);
exec($cmd, $out, $rc);
if ($rc !== 0) {
    $cleanup();
    $fail('The import stopped: ' . implode(' ', $out));
}

$say('4. Compare');
$bad = 0;
$tablesOf = static fn (string $db): array => $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = ' . $pdo->quote($db) . " AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$tables = $tablesOf($live);
$restored = $tablesOf($drill);
$rows = 0;
foreach ($tables as $t) {
    if (!in_array($t, $restored, true)) {
        $say("   {$t}: MISSING in the restore");
        $bad++;
        continue;
    }
    $a = (int) $pdo->query("SELECT COUNT(*) FROM `{$live}`.`{$t}`")->fetchColumn();
    $b = (int) $pdo->query("SELECT COUNT(*) FROM `{$drill}`.`{$t}`")->fetchColumn();
    $same = $a === $b
        && $pdo->query("CHECKSUM TABLE `{$live}`.`{$t}`")->fetch(PDO::FETCH_NUM)[1] === $pdo->query("CHECKSUM TABLE `{$drill}`.`{$t}`")->fetch(PDO::FETCH_NUM)[1];
    $rows += $a;
    if (!$same) {
        $say(sprintf('   %-22s live %d rows, restored %d rows: DIFFERENT', $t, $a, $b));
        $bad++;
    }
}
$say(sprintf('   %d tables, %d rows: %s', count($tables), $rows, $bad === 0 ? 'identical' : "{$bad} different"));

$figures = [
    'Sales total (USD)'   => "SELECT COALESCE(SUM(total_usd), 0) FROM `%s`.sales WHERE status = 'completed'",
    'Payments (USD)'      => 'SELECT COALESCE(SUM(amount_usd), 0) FROM `%s`.sale_payments',
    'Refunds (USD)'       => 'SELECT COALESCE(SUM(total_usd), 0) FROM `%s`.returns',
    'Customer debt (USD)' => 'SELECT COALESCE(SUM(amount_usd), 0) FROM `%s`.customer_ledger',
    'Stock value (USD)'   => 'SELECT COALESCE(ROUND(SUM(stock_base * cost_per_base), 2), 0) FROM `%s`.products',
    'Last invoice'        => "SELECT COALESCE(MAX(invoice_no), '-') FROM `%s`.sales",
    'Arabic names'        => "SELECT COALESCE(MD5(GROUP_CONCAT(HEX(name) ORDER BY id)), '-') FROM `%s`.products WHERE name REGEXP '[^ -~]'",
];
foreach ($figures as $label => $sql) {
    $a = (string) $pdo->query(sprintf($sql, $live))->fetchColumn();
    $b = (string) $pdo->query(sprintf($sql, $drill))->fetchColumn();
    $shown = $label === 'Arabic names' ? ($a === '-' ? 'none in the data' : 'same bytes') : $a;
    $say(sprintf('   %-20s %s', $label, $a === $b ? $shown : "DIFFERENT (live {$a}, restored {$b})"));
    $bad += $a === $b ? 0 : 1;
}

$hashes = static function (string $dir): array {
    $found = [];
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            $rel = str_replace('\\', '/', substr((string) $f, strlen($dir) + 1));
            if (!str_starts_with($rel, 'test/')) {
                $found[$rel] = md5_file((string) $f);
            }
        }
    }
    ksort($found);

    return $found;
};
$onDisk = $hashes(BASE_PATH . '/public/uploads');
$inZip = $hashes($work . '/uploads');
$say(sprintf('   Uploaded files       %d on disk, %d in the backup: %s', count($onDisk), count($inZip), $onDisk === $inZip ? 'identical' : 'DIFFERENT'));
$bad += $onDisk === $inZip ? 0 : 1;

$say('5. Clean up');
$cleanup();
$say("   {$drill} dropped; the backup stays in storage/backups");

$say('');
if ($bad > 0) {
    $fail("{$bad} difference(s). If the shop was selling while this ran, run it again when the till is quiet.");
}
$say('OK: this backup restores to exactly what is in the live database.');
