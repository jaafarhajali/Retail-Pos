<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Applies database/migrations/*.sql in name order, each exactly once.
 * A file is recorded in `migrations` only after all its statements ran.
 * File rule: statements end with ";" at the end of a line, comments are
 * whole lines starting with "--", and no string literal spans lines.
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $dir)
    {
    }

    /** @return string[] filenames applied by this call */
    public function run(): array
    {
        $applied = [];
        foreach ($this->pending() as $file) {
            $sql = (string) file_get_contents($this->dir . '/' . $file);
            foreach (self::splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            $this->pdo->prepare('INSERT INTO migrations (filename) VALUES (:f)')->execute(['f' => $file]);
            $applied[] = $file;
        }

        return $applied;
    }

    /** @return string[] migration files not yet applied, in name order */
    public function pending(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                filename VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $done = $this->pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = array_map('basename', glob($this->dir . '/*.sql') ?: []);
        sort($files);

        return array_values(array_diff($files, $done));
    }

    /** @return string[] */
    public static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $kept = array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '--'));
        $parts = preg_split('/;\s*$/m', implode("\n", $kept)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
