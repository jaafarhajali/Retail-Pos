<?php
/**
 * php bin/reset-data.php [--yes] [--no-seed] [--keep-products]
 *
 * A fresh start for testing: takes a backup of everything first (the old data is in that zip),
 * then empties the sales, cash sessions, purchases, returns, expenses, stock, customers,
 * suppliers, products and categories, resets the invoice and session numbering, and seeds
 * the demo data again.
 *
 * It keeps what you would hate to redo: users and their passwords and PINs, roles and
 * permissions, registers and their device links, settings, the shop logo and the exchange rate.
 *
 *   --yes            skip the confirmation prompt
 *   --no-seed        leave the database empty instead of seeding the demo data
 *   --keep-products  keep products, categories and their images (stock goes back to zero)
 *
 * Refuses to run on a production installation.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Services\BackupService;

$opts = getopt('', ['yes', 'no-seed', 'keep-products']);
$out = static fn (string $line) => print($line . "\n");
$keepProducts = isset($opts['keep-products']);

if (APP_ENV === 'production') {
    fwrite(STDERR, "Refusing to reset a production installation (app.env = production in config/app.ini).\n");
    exit(1);
}

/** Tables left untouched. Everything else is emptied. */
$keep = ['migrations', 'users', 'roles', 'permissions', 'role_permissions', 'registers', 'settings', 'exchange_rates'];
if ($keepProducts) {
    $keep = [...$keep, 'categories', 'products', 'product_units', 'barcodes'];
}
$pdo = Database::pdo();
$tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = ' . $pdo->quote(DB_NAME) . " AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$wipe = array_values(array_diff($tables, $keep));
$counts = [];
foreach ($wipe as $t) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    if ($n > 0) {
        $counts[$t] = $n;
    }
}

$out('Database: ' . DB_NAME);
$out('Will empty: ' . ($counts === [] ? 'nothing (already empty)' : implode(', ', array_map(static fn (string $t, int $n): string => "{$t} ({$n})", array_keys($counts), $counts))));
$out('Will keep: ' . implode(', ', $keep));
$out(isset($opts['no-seed']) ? 'Then: leave it empty.' : 'Then: seed the demo data.');
if (!isset($opts['yes'])) {
    echo 'A backup is taken first. Go ahead? [y/N] ';
    $answer = trim((string) fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        $out('Cancelled. Nothing changed.');
        exit(0);
    }
}

$out('1. Backup');
try {
    $zip = (new BackupService())->create();
} catch (\Throwable $e) {
    fwrite(STDERR, 'No reset without a backup: ' . $e->getMessage() . "\n");
    exit(1);
}
$out('   ' . basename($zip) . ' in storage/backups: the old data is in there.');

$out('2. Empty the tables');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    foreach ($wipe as $t) {
        $pdo->exec("TRUNCATE TABLE `{$t}`");
    }
    if ($keepProducts) {
        $pdo->exec('UPDATE products SET stock_base = 0');
    }
    // Numbering starts again: INV-000001, S-000001... Product codes go on unless products were wiped too.
    $pdo->exec("INSERT INTO counters (name, next_value) VALUES ('invoice', 1), ('session', 1), ('z', 1), ('return', 1), ('purchase', 1), ('count', 1), ('product', " . ($keepProducts ? (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM products')->fetchColumn() : 1) . ')'
        . ' ON DUPLICATE KEY UPDATE next_value = VALUES(next_value)');
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}
// A register whose session is gone is free again; its device link stays.
$out('   ' . count($wipe) . ' tables emptied, numbering back to 1.');

if (!$keepProducts) {
    $dir = UPLOADS_PATH . '/products';
    $removed = 0;
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) && basename($file) !== '.htaccess') {
            unlink($file);
            $removed++;
        }
    }
    $out("   {$removed} product image(s) removed; the logo stays.");
}

$_SESSION = [];
Auth::login(1);
Audit::log('data.reset', null, null, ['backup' => basename($zip), 'kept_products' => $keepProducts, 'tables' => count($wipe)]);

if (isset($opts['no-seed'])) {
    $out('Done. The database is empty except for ' . implode(', ', $keep) . '.');
    exit(0);
}

$out('3. Seed');
$cmd = sprintf('"%s" "%s" --yes%s', PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'seed-demo.php', $keepProducts ? ' --force' : '');
passthru($cmd, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "The seed did not finish (exit code {$rc}). The backup from step 1 is untouched.\n");
    exit($rc);
}
$out('Done. Old data: ' . basename($zip) . '. New data: the demo shop.');
