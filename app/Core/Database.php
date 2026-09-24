<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Shared PDO connection. The MariaDB session timezone is pinned to PHP's,
 * so NOW()/CURRENT_TIMESTAMP and date() always agree.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect(DB_NAME);
    }

    /** Connect to $dbName, or to the server only when $dbName is null. */
    public static function connect(?string $dbName): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT)
             . ($dbName !== null ? ';dbname=' . $dbName : '');
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");

        return $pdo;
    }

    /** Run $fn inside a transaction. Joins an already-open one, so services can nest. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
    }
}
