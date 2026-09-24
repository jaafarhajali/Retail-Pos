<?php
declare(strict_types=1);

// Everything the tests touch uses the separate *_test database.
putenv('RETAIL_POS_ENV=test');
$_SESSION = [];

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/support/assert.php';

const TEST_ADMIN_PASSWORD = 'admin-pass-123';

/** Rebuild the test database from scratch, so every test starts from the same state. */
function test_db_reset(): void
{
    \App\Core\Database::connect(null)->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
    \App\Core\Database::disconnect();
    \App\Core\Installer::createDatabase();
    \App\Core\Installer::migrate();
    \App\Core\Installer::ensureAdmin(TEST_ADMIN_PASSWORD);
    \App\Core\Settings::flush();
    $_SESSION = [];
    \App\Core\Auth::forget();
    \App\Core\Gate::forget();
}

/** The admin created by test_db_reset() (first row of a fresh users table). */
const TEST_ADMIN_ID = 1;

/** Create an active user directly (no forced password change) and return its id. */
function make_user(string $username, int $roleId = \App\Models\Role::CASHIER_ID, string $password = 'password123'): int
{
    return (new \App\Models\User())->create($username, $password, ucfirst($username), $roleId, false);
}
