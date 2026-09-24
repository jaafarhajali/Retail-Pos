<?php
/**
 * php bin/install.php [--admin-password=secret123]
 * Creates the database if missing, applies pending migrations, and creates
 * the first Admin account when no user exists. Safe to run repeatedly.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Installer;

$opts = getopt('', ['admin-password:']);

try {
    Installer::createDatabase();
    $applied = Installer::migrate();
    echo $applied === [] ? "Database is up to date.\n" : 'Applied: ' . implode(', ', $applied) . "\n";

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
