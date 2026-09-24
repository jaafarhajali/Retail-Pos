<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Expense extends Model
{
    private const SELECT = 'SELECT e.*, u.username, s.name AS supplier_name FROM expenses e LEFT JOIN users u ON u.id = e.user_id LEFT JOIN suppliers s ON s.id = e.supplier_id';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE e.id = :id', ['id' => $id]);
    }

    public function search(array $filters, int $page): array
    {
        $where = ['1=1'];
        $params = [];
        if ($filters['from'] !== '') {
            $where[] = 'e.expense_date >= :f';
            $params['f'] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $where[] = 'e.expense_date <= :t';
            $params['t'] = $filters['to'];
        }
        if ($filters['category'] !== '') {
            $where[] = 'e.category = :c';
            $params['c'] = $filters['category'];
        }
        $w = implode(' AND ', $where);

        return $this->paginate(self::SELECT . " WHERE {$w} ORDER BY e.id DESC", "SELECT COUNT(*) FROM expenses e WHERE {$w}", $params, $page, 50);
    }

    public function categories(): array
    {
        return array_column($this->fetchAll('SELECT DISTINCT category FROM expenses ORDER BY category'), 'category');
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO expenses (category, description, amount, currency, amount_usd, exchange_rate, paid_from, session_id, supplier_id, user_id, expense_date)
             VALUES (:c, :d, :a, :cur, :au, :x, :pf, :s, :sup, :u, :dt)',
            [
                'c' => $f['category'], 'd' => $f['description'], 'a' => $f['amount'], 'cur' => $f['currency'], 'au' => $f['amount_usd'], 'x' => $f['exchange_rate'],
                'pf' => $f['paid_from'], 's' => $f['session_id'], 'sup' => $f['supplier_id'], 'u' => $f['user_id'], 'dt' => $f['expense_date'],
            ]
        );

        return $this->lastId();
    }

    /** Expenses in a period, excluding supplier payments (they pay for stock already in COGS). */
    public function totalUsd(string $from, string $to, bool $excludeSupplierPayments = true): string
    {
        return number_format((float) $this->fetchValue(
            'SELECT COALESCE(SUM(amount_usd), 0) FROM expenses WHERE expense_date BETWEEN :f AND :t' . ($excludeSupplierPayments ? ' AND supplier_id IS NULL' : ''),
            ['f' => $from, 't' => $to]
        ), 2, '.', '');
    }
}
