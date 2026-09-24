<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Database creation, migrations and the first Admin account.
 * Used by bin/install.php and by the test suite. Safe to run repeatedly.
 */
final class Installer
{
    public static function createDatabase(): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', DB_NAME)) {
            throw new \RuntimeException('Invalid database name: ' . DB_NAME);
        }
        Database::connect(null)->exec(
            'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        Database::disconnect();
    }

    /** @return string[] migration files applied now */
    public static function migrate(): array
    {
        return (new Migrator(Database::pdo(), BASE_PATH . '/database/migrations'))->run();
    }

    /**
     * Create the Admin account when no user exists yet.
     * No password given → a random one is generated and must be changed at first sign-in.
     *
     * @return string|null the password that was set, or null when users already exist
     */
    public static function ensureAdmin(?string $password): ?string
    {
        $pdo = Database::pdo();
        if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            return null;
        }
        $generated = $password === null || $password === '';
        $password = $generated ? bin2hex(random_bytes(6)) : (string) $password;
        if (mb_strlen($password) < 8) {
            throw new \InvalidArgumentException('The admin password must be at least 8 characters.');
        }
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, role_id, must_change_password)
             VALUES (:u, :h, :n, 1, :m)'
        )->execute([
            'u' => 'admin',
            'h' => password_hash($password, PASSWORD_DEFAULT),
            'n' => 'Administrator',
            'm' => (int) $generated,
        ]);

        return $password;
    }
}
