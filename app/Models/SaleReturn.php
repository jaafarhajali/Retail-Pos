<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Returns linked to an invoice (spec §3.8, §9). */
final class SaleReturn extends Model
{
    private const SELECT = 'SELECT r.*, s.invoice_no, u.username, c.name AS customer_name FROM returns r JOIN sales s ON s.id = r.sale_id
                            JOIN users u ON u.id = r.user_id LEFT JOIN customers c ON c.id = r.customer_id';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE r.id = :id', ['id' => $id]);
    }

    public function recent(int $limit = 100): array
    {
        return $this->fetchAll(self::SELECT . ' ORDER BY r.id DESC LIMIT ' . $limit);
    }

    public function forSale(int $saleId): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE r.sale_id = :s ORDER BY r.id', ['s' => $saleId]);
    }

    public function items(int $returnId): array
    {
        return $this->fetchAll(
            'SELECT ri.*, si.product_name, si.unit_name, si.product_id FROM return_items ri JOIN sale_items si ON si.id = ri.sale_item_id WHERE ri.return_id = :r ORDER BY ri.id',
            ['r' => $returnId]
        );
    }

    public function refunds(int $returnId): array
    {
        return $this->fetchAll('SELECT * FROM return_refunds WHERE return_id = :r ORDER BY id', ['r' => $returnId]);
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO returns (return_no, sale_id, session_id, register_id, user_id, customer_id, total_usd, exchange_rate, reason) VALUES (:no, :s, :ses, :r, :u, :c, :t, :x, :re)',
            [
                'no' => $f['return_no'], 's' => $f['sale_id'], 'ses' => $f['session_id'], 'r' => $f['register_id'], 'u' => $f['user_id'],
                'c' => $f['customer_id'], 't' => $f['total_usd'], 'x' => $f['exchange_rate'], 're' => $f['reason'],
            ]
        );

        return $this->lastId();
    }

    public function addItem(int $returnId, array $l): int
    {
        $this->execute(
            'INSERT INTO return_items (return_id, sale_item_id, qty, base_qty, item_condition, refund_usd, cost_usd) VALUES (:r, :si, :q, :b, :c, :ref, :cost)',
            ['r' => $returnId, 'si' => $l['sale_item_id'], 'q' => $l['qty'], 'b' => $l['base_qty'], 'c' => $l['item_condition'], 'ref' => $l['refund_usd'], 'cost' => $l['cost_usd']]
        );

        return $this->lastId();
    }

    public function addRefund(int $returnId, string $method, string $currency, string $amount, string $amountUsd): void
    {
        $this->execute('INSERT INTO return_refunds (return_id, method, currency, amount, amount_usd) VALUES (:r, :m, :c, :a, :u)',
            ['r' => $returnId, 'm' => $method, 'c' => $currency, 'a' => $amount, 'u' => $amountUsd]);
    }

    /** Refund and cost totals of returns in a session or period (X/Z and reports). */
    public function totals(string $where, array $params): array
    {
        return $this->fetch(
            "SELECT COUNT(*) AS n, COALESCE(SUM(r.total_usd), 0) AS refund_usd,
                    COALESCE((SELECT SUM(ri.cost_usd) FROM return_items ri JOIN returns r2 ON r2.id = ri.return_id WHERE {$where}), 0) AS cost_usd
             FROM returns r WHERE " . str_replace('r2.', 'r.', $where),
            $params + array_combine(array_map(static fn ($k) => $k . '2', array_keys($params)), array_values($params))
        ) ?? ['n' => 0, 'refund_usd' => '0', 'cost_usd' => '0'];
    }
}
