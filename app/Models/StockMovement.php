<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Append-only stock history. products.stock_base is updated only here, from StockService. */
final class StockMovement extends Model
{
    public function add(array $f): int
    {
        $this->execute(
            'INSERT INTO stock_movements (product_id, base_qty, type, reason, cost_per_base, ref_type, ref_id, user_id, session_id)
             VALUES (:p, :q, :t, :r, :c, :rt, :ri, :u, :s)',
            [
                'p' => $f['product_id'], 'q' => $f['base_qty'], 't' => $f['type'], 'r' => $f['reason'] ?? null,
                'c' => $f['cost_per_base'], 'rt' => $f['ref_type'] ?? null, 'ri' => $f['ref_id'] ?? null,
                'u' => $f['user_id'] ?? null, 's' => $f['session_id'] ?? null,
            ]
        );
        $this->execute('UPDATE products SET stock_base = stock_base + :q WHERE id = :p', ['q' => $f['base_qty'], 'p' => $f['product_id']]);

        return $this->lastId();
    }

    /** Lock the product row and return its current stock and cost. */
    public function lockProduct(int $productId): array
    {
        return $this->fetch('SELECT id, stock_base, cost_per_base, base_unit, name FROM products WHERE id = :p FOR UPDATE', ['p' => $productId])
            ?? throw new \DomainException('Product not found.');
    }

    public function setCost(int $productId, string $costPerBase): void
    {
        $this->execute('UPDATE products SET cost_per_base = :c WHERE id = :p', ['c' => $costPerBase, 'p' => $productId]);
    }

    public function hasMovements(int $productId): bool
    {
        return (bool) $this->fetchValue('SELECT COUNT(*) FROM stock_movements WHERE product_id = :p', ['p' => $productId]);
    }

    public function search(array $filters, int $page): array
    {
        $where = ['1=1'];
        $params = [];
        if ($filters['product_id'] > 0) {
            $where[] = 'm.product_id = :p';
            $params['p'] = $filters['product_id'];
        }
        if ($filters['type'] !== '') {
            $where[] = 'm.type = :t';
            $params['t'] = $filters['type'];
        }
        if ($filters['from'] !== '') {
            $where[] = 'm.created_at >= :f';
            $params['f'] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            $where[] = 'm.created_at <= :o';
            $params['o'] = $filters['to'] . ' 23:59:59';
        }
        $w = implode(' AND ', $where);

        return $this->paginate(
            "SELECT m.*, p.name AS product_name, p.base_unit, p.internal_code, u.username FROM stock_movements m
             JOIN products p ON p.id = m.product_id LEFT JOIN users u ON u.id = m.user_id WHERE {$w} ORDER BY m.id DESC",
            "SELECT COUNT(*) FROM stock_movements m WHERE {$w}",
            $params, $page, 50
        );
    }

    /** Waste cost in a period (report §16). */
    public function wasteCost(string $from, string $to): string
    {
        return number_format((float) $this->fetchValue(
            "SELECT COALESCE(SUM(-base_qty * cost_per_base), 0) FROM stock_movements WHERE type = 'waste' AND created_at BETWEEN :f AND :t",
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']
        ), 2, '.', '');
    }
}
