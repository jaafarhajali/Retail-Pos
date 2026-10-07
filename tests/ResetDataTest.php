<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

/** bin/reset-data.php: a backup first, then an empty shop with the people, registers and settings kept. */
$run = static function (string $args): array {
    $cmd = sprintf('"%s" "%s" %s 2>&1', PHP_BINARY, dirname(__DIR__) . '/bin/reset-data.php', $args);   // the test env is inherited: RETAIL_POS_ENV=test
    exec($cmd, $lines, $rc);
    Database::disconnect();

    return [$rc, implode("\n", $lines)];
};
$count = static fn (string $table): int => (int) Database::pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

return [
    '__before' => 'test_db_reset',

    'the old data goes to a backup, the shop is emptied and seeded again, people and registers stay' => function () use ($run, $count): void {
        exec(sprintf('"%s" "%s" --yes 2>&1', PHP_BINARY, dirname(__DIR__) . '/bin/seed-demo.php'), $ignored, $rc);
        Database::disconnect();
        assert_same(0, $rc, 'seeding first');
        make_user('omar');   // the seed makes sara and ali itself
        (new \App\Models\User())->setPin(TEST_ADMIN_ID, '2468');
        (new \App\Models\Setting())->setMany(['shop_name' => 'Mr.Shisha']);
        $registers = new \App\Models\Register();
        $registers->bind(1, hash('sha256', 'device'));
        $before = ['sales' => $count('sales'), 'products' => $count('products'), 'sessions' => $count('cash_sessions')];
        assert_true($before['sales'] > 0 && $before['products'] > 0 && $before['sessions'] > 0, 'there was something to empty');
        $backupsBefore = glob(\App\Services\BackupService::dir() . '/*.zip') ?: [];

        [$rc, $log] = $run('--yes');
        assert_same(0, $rc, $log);
        assert_contains('the old data is in there', $log);
        // one new zip, not "one more": the backup folder keeps its 30 newest, so a full folder stays at 30
        assert_same(1, count(array_diff(glob(\App\Services\BackupService::dir() . '/*.zip') ?: [], $backupsBefore)), 'one backup was taken before anything was emptied');

        // Seeded again: the same demo shop, numbered from 1.
        assert_same($before['products'], $count('products'));
        assert_same('INV-000001', Database::pdo()->query('SELECT MIN(invoice_no) FROM sales')->fetchColumn());
        assert_same('S-000001', Database::pdo()->query('SELECT session_no FROM cash_sessions ORDER BY id LIMIT 1')->fetchColumn());
        assert_same('P-000001', Database::pdo()->query('SELECT MIN(internal_code) FROM products')->fetchColumn());

        // Kept: people, PIN, registers and their device links, settings.
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE username = 'omar'")->fetchColumn());
        assert_true(password_verify('2468', (string) (new \App\Models\User())->find(TEST_ADMIN_ID)['pin_hash']));
        assert_same('Mr.Shisha', \App\Core\Settings::get('shop_name'));
        assert_same(hash('sha256', 'device'), $registers->find(1)['device_token_hash']);
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'data.reset'")->fetchColumn());
    },

    '--no-seed leaves it empty; --keep-products keeps the catalogue with zero stock' => function () use ($run, $count): void {
        exec(sprintf('"%s" "%s" --yes 2>&1', PHP_BINARY, dirname(__DIR__) . '/bin/seed-demo.php'), $ignored, $rc);
        Database::disconnect();
        $products = $count('products');
        [$rc, $log] = $run('--yes --keep-products --no-seed');
        assert_same(0, $rc, $log);
        assert_same($products, $count('products'));
        assert_same(0, $count('sales'));
        assert_same(0, $count('stock_movements'));
        assert_same(0, (int) Database::pdo()->query('SELECT COALESCE(SUM(stock_base), 0) FROM products')->fetchColumn(), 'stock back to zero');
        assert_true($count('barcodes') > 0, 'barcodes stay with their products');

        [$rc, $log] = $run('--yes --no-seed');
        assert_same(0, $rc, $log);
        assert_same(0, $count('products'));
        assert_same(0, $count('categories'));
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn(), 'the administrator stays');
        assert_same(1, $count('exchange_rates'));
    },

    'without --yes it asks and, with no answer, changes nothing' => function () use ($run, $count): void {
        make_product('Kept', 'piece');
        [$rc, $log] = $run('< NUL');
        assert_same(0, $rc);
        assert_contains('Cancelled. Nothing changed.', $log);
        assert_same(1, $count('products'));
    },
];
