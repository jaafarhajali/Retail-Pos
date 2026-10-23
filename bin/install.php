<?php
/**
 * php bin/install.php [--admin-password=secret123] [--shop-name="Mr. Shisha"] [--runner=service|task] [--services="A,B,C"]
 * Creates the database if missing, applies pending migrations, and creates
 * the first Admin account when no user exists. Safe to run repeatedly.
 * The Windows setup (tools/installer) passes the shop name, that a service runs the backups, and the service names
 * the "Check this installation" page watches (2026-10-23).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Installer;

$opts = getopt('', ['admin-password:', 'shop-name:', 'runner:', 'services:']);

try {
    Installer::createDatabase();
    $applied = Installer::migrate();
    echo $applied === [] ? "Database is up to date.\n" : 'Applied: ' . implode(', ', $applied) . "\n";

    $settings = [];
    if (isset($opts['shop-name']) && trim((string) $opts['shop-name']) !== '') {
        $settings['shop_name'] = mb_substr(trim((string) $opts['shop-name']), 0, 100);
    }
    if (isset($opts['runner'])) {
        $settings['backup_runner'] = (string) $opts['runner'] === 'service' ? 'service' : 'task';
    }
    if (isset($opts['services'])) {
        $settings['install_services'] = (string) $opts['services'];
    }
    if ($settings !== []) {
        (new App\Models\Setting())->setMany($settings);
        echo 'Settings: ', implode(', ', array_keys($settings)), "\n";
    }
    $given = isset($opts['admin-password']) ? (string) $opts['admin-password'] : null;
    $password = Installer::ensureAdmin($given);
    if ($password !== null) {
        echo "Admin account created.\n  username: admin\n  password: {$password}\n";
        if ($given === null) {
            echo "  (generated — it must be changed at first sign-in)\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
