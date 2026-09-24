<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Purchase extends Model
{
    private const SELECT = 'SELECT p.*, s.name AS supplier_name, u.username FROM purchases p
                            LEFT JOIN suppliers s ON s.id = p.supplier_id LEFT JOIN users u ON u.id = p.user_id';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE p.id = :id', ['id' => $id]);
    }

    public function items(int $purchaseId): array
    {
        return $this->fetchAll(
            'SELECT i.*, pr.name AS product_name, pr.base_unit, pu.name AS unit_name FROM purchase_items i
             JOIN products pr ON pr.id = i.product_id JOIN product_units pu ON pu.id = i.product_unit_id WHERE i.purchase_id = :p ORDER BY i.id',
            ['p' => $purchaseId]
        );
    }

    public function search(array $filters, int $page): array
    {
        $where = ['1=1'];
        $params = [];
        if ($filters['supplier_id'] > 0) {
            $where[] = 'p.supplier_id = :s';
            $params['s'] = $filters['supplier_id'];
        }
        if ($filters['from'] !== '') {
            $where[] = 'p.purchase_date >= :f';
            $params['f'] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $where[] = 'p.purchase_date <= :t';
            $params['t'] = $filters['to'];
        }
        $w = implode(' AND ', $where);

        return $this->paginate(self::SELECT . " WHERE {$w} ORDER BY p.id DESC", "SELECT COUNT(*) FROM purchases p WHERE {$w}", $params, $page, 25);
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO purchases (purchase_no, supplier_id, supplier_invoice_ref, purchase_date, total_usd, paid_now_usd, notes, user_id, session_id)
             VALUES (:no, :s, :ref, :d, :t, :paid, :n, :u, :ses)',
            [
                'no' => $f['purchase_no'], 's' => $f['supplier_id'], 'ref' => $f['supplier_invoice_ref'], 'd' => $f['purchase_date'],
                't' => $f['total_usd'], 'paid' => $f['paid_now_usd'], 'n' => $f['notes'], 'u' => $f['user_id'], 'ses' => $f['session_id'],
            ]
        );

        return $this->lastId();
    }

    public function addItem(int $purchaseId, array $l): int
    {
        $this->execute(
            'INSERT INTO purchase_items (purchase_id, product_id, product_unit_id, qty, base_qty, unit_cost_usd, line_total_usd, cost_per_base)
             VALUES (:p, :pr, :u, :q, :b, :c, :t, :cb)',
            [
                'p' => $purchaseId, 'pr' => $l['product_id'], 'u' => $l['product_unit_id'], 'q' => $l['qty'], 'b' => $l['base_qty'],
                'c' => $l['unit_cost_usd'], 't' => $l['line_total_usd'], 'cb' => $l['cost_per_base'],
            ]
        );

        return $this->lastId();
    }
}
