<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Installer;
use App\Core\Migrator;

return [
    '__before' => 'test_db_reset',

    'splitStatements drops comment lines and splits on end-of-line semicolons' => function (): void {
        $sql = "-- a comment\nCREATE TABLE a (x INT);\n\nINSERT INTO a VALUES (1);\n";
        assert_same(['CREATE TABLE a (x INT)', 'INSERT INTO a VALUES (1)'], Migrator::splitStatements($sql));
    },

    'a semicolon inside a line does not split the statement' => function (): void {
        assert_same(["INSERT INTO t VALUES ('a;b')"], Migrator::splitStatements("INSERT INTO t VALUES ('a;b');\n"));
    },

    'a fresh install records every migration and a second run applies nothing' => function (): void {
        $applied = Database::pdo()->query('SELECT filename FROM migrations ORDER BY filename')->fetchAll(PDO::FETCH_COLUMN);
        assert_same(['001_foundation.sql', '002_catalog.sql', '003_operations.sql', '004_admin_closes_sessions.sql'], $applied);
        assert_same([], Installer::migrate());
    },

    'all foundation tables exist' => function (): void {
        $tables = Database::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'roles', 'permissions', 'role_permissions', 'login_attempts', 'audit_log',
                  'settings', 'registers', 'exchange_rates', 'counters', 'migrations'] as $table) {
            assert_true(in_array($table, $tables, true), "missing table {$table}");
        }
    },

    'seed data: roles, permissions, cashier defaults, rate, counters, settings' => function (): void {
        $pdo = Database::pdo();
        assert_same(1, (int) $pdo->query("SELECT is_super FROM roles WHERE name = 'Admin'")->fetchColumn());
        assert_same(39, (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn());
        $cashier = $pdo->query(
            "SELECT rp.perm_key FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'Cashier' ORDER BY rp.perm_key"
        )->fetchAll(PDO::FETCH_COLUMN);
        assert_same(['debt.collect', 'pos.use', 'return.create', 'sale.create', 'sale.credit',
                     'sale.reprint', 'session.open_own'], $cashier, '004 removed session.close_own: the admin closes');
        assert_same(90000, (int) $pdo->query('SELECT lbp_per_usd FROM exchange_rates ORDER BY id DESC LIMIT 1')->fetchColumn());
        assert_same(7, (int) $pdo->query('SELECT COUNT(*) FROM counters')->fetchColumn(), '6 foundation counters + product (002)');
        assert_same('5000', $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'lbp_rounding_step'")->fetchColumn());
    },

    'the first admin is created once, with the given password' => function (): void {
        $row = Database::pdo()->query(
            "SELECT u.password_hash, u.must_change_password, r.name AS role
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = 'admin'"
        )->fetch();
        assert_true(password_verify(TEST_ADMIN_PASSWORD, $row['password_hash']), 'password should verify');
        assert_same(0, (int) $row['must_change_password']);
        assert_same('Admin', $row['role']);
        assert_same(null, Installer::ensureAdmin('another-password'));
    },

    'a generated admin password must be changed at first sign-in' => function (): void {
        Database::pdo()->exec('DELETE FROM users');
        $password = Installer::ensureAdmin(null);
        assert_same(12, strlen((string) $password));
        assert_same(1, (int) Database::pdo()->query("SELECT must_change_password FROM users WHERE username = 'admin'")->fetchColumn());
    },

    'database NOW() agrees with PHP time (same timezone)' => function (): void {
        $dbNow = strtotime((string) Database::pdo()->query('SELECT NOW()')->fetchColumn());
        assert_true(abs($dbNow - time()) <= 5, 'DB and PHP clocks differ by ' . ($dbNow - time()) . ' s');
    },

    'arabic text survives a round trip' => function (): void {
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE settings SET setting_value = :v WHERE setting_key = 'shop_name'")
            ->execute(['v' => 'معسل تفاحتين']);
        assert_same('معسل تفاحتين', $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'shop_name'")->fetchColumn());
    },
];
