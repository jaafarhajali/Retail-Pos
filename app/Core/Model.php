<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Base model: thin PDO helpers. Every query is a prepared statement. */
abstract class Model
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /** @return array<int, array<string, mixed>> */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    protected function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    protected function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /** @return int affected rows */
    protected function execute(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    protected function lastId(): int
    {
        return (int) $this->db->lastInsertId();
    }

    /**
     * @param string $sql      full SELECT without LIMIT
     * @param string $countSql matching SELECT COUNT(*) using exactly the same placeholders
     * @return array{rows: array, total: int, page: int, pages: int, per_page: int}
     */
    protected function paginate(string $sql, string $countSql, array $params, int $page, int $perPage = 25): array
    {
        $total = (int) $this->fetchValue($countSql, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $rows = $this->fetchAll($sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }
}
