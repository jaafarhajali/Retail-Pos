<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Stocktaking (spec §3.4, §12). */
final class StockCount extends Model
{
    private const SELECT = 'SELECT c.*, u1.username AS started_by_name, u2.username AS confirmed_by_name, cat.name AS category_name FROM stock_counts c
                            JOIN users u1 ON u1.id = c.started_by LEFT JOIN users u2 ON u2.id = c.confirmed_by LEFT JOIN categories cat ON cat.id = c.category_id';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE c.id = :id', ['id' => $id]);
    }

    public function recent(int $limit = 50): array
    {
        return $this->fetchAll(self::SELECT . ' ORDER BY c.id DESC LIMIT ' . $limit);
    }

    public function openCount(): ?array
    {
        return $this->fetch(self::SELECT . " WHERE c.status = 'open' ORDER BY c.id DESC LIMIT 1");
    }

    public function create(string $countNo, string $scope, ?int $categoryId, int $userId, ?string $note): int
    {
        $this->execute('INSERT INTO stock_counts (count_no, scope, category_id, started_by, note) VALUES (:n, :s, :c, :u, :o)',
            ['n' => $countNo, 's' => $scope, 'c' => $categoryId, 'u' => $userId, 'o' => $note]);

        return $this->lastId();
    }

    public function addLine(int $countId, int $productId, int $expectedBase, string $costPerBase): void
    {
        $this->execute('INSERT INTO stock_count_lines (count_id, product_id, expected_base, cost_per_base) VALUES (:c, :p, :e, :k)',
            ['c' => $countId, 'p' => $productId, 'e' => $expectedBase, 'k' => $costPerBase]);
    }

    public function lines(int $countId): array
    {
        return $this->fetchAll(
            'SELECT l.*, p.name AS product_name, p.internal_code, p.base_unit, p.stock_base AS current_base FROM stock_count_lines l
             JOIN products p ON p.id = l.product_id WHERE l.count_id = :c ORDER BY p.name',
            ['c' => $countId]
        );
    }

    public function setCounted(int $lineId, ?int $countedBase): void
    {
        $this->execute('UPDATE stock_count_lines SET counted_base = :n, diff_base = CASE WHEN :m IS NULL THEN NULL ELSE :k - expected_base END WHERE id = :id',
            ['n' => $countedBase, 'm' => $countedBase, 'k' => $countedBase, 'id' => $lineId]);
    }

    public function setStatus(int $id, string $status, ?int $userId = null): void
    {
        $this->execute(
            "UPDATE stock_counts SET status = :s, confirmed_by = CASE WHEN :s2 = 'confirmed' THEN :u ELSE confirmed_by END,
             confirmed_at = CASE WHEN :s3 = 'confirmed' THEN NOW() ELSE confirmed_at END WHERE id = :id",
            ['s' => $status, 's2' => $status, 'u' => $userId, 's3' => $status, 'id' => $id]
        );
    }
}
