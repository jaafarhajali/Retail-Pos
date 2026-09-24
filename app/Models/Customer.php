<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Customers and their ledger: balance = SUM(amount_usd); + is debt, − reduces it (spec §3.5, §10). */
final class Customer extends Model
{
    private const SELECT = 'SELECT c.*, COALESCE((SELECT SUM(l.amount_usd) FROM customer_ledger l WHERE l.customer_id = c.id), 0) AS balance_usd FROM customers c';

    public function all(bool $activeOnly = false): array
    {
        return $this->fetchAll(self::SELECT . ($activeOnly ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.name');
    }

    public function search(string $q): array
    {
        $like = '%' . addcslashes($q, '%_\\') . '%';

        return $this->fetchAll(self::SELECT . ' WHERE c.is_active = 1 AND (c.name LIKE :q1 OR c.phone LIKE :q2) ORDER BY c.name LIMIT 20', ['q1' => $like, 'q2' => $like]);
    }

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE c.id = :id', ['id' => $id]);
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO customers (name, phone, notes, default_price_level, credit_limit_usd) VALUES (:n, :p, :o, :l, :c)',
            ['n' => $f['name'], 'p' => $f['phone'], 'o' => $f['notes'], 'l' => $f['default_price_level'], 'c' => $f['credit_limit_usd']]
        );

        return $this->lastId();
    }

    public function update(int $id, array $f): void
    {
        $this->execute(
            'UPDATE customers SET name = :n, phone = :p, notes = :o, default_price_level = :l, credit_limit_usd = :c, is_active = :a WHERE id = :id',
            ['n' => $f['name'], 'p' => $f['phone'], 'o' => $f['notes'], 'l' => $f['default_price_level'], 'c' => $f['credit_limit_usd'], 'a' => (int) $f['is_active'], 'id' => $id]
        );
    }

    public function balance(int $id): string
    {
        return number_format((float) $this->fetchValue('SELECT COALESCE(SUM(amount_usd), 0) FROM customer_ledger WHERE customer_id = :c', ['c' => $id]), 2, '.', '');
    }

    public function ledger(int $customerId, int $limit = 200): array
    {
        return $this->fetchAll(
            'SELECT l.*, u.username, s.invoice_no FROM customer_ledger l LEFT JOIN users u ON u.id = l.user_id
             LEFT JOIN sales s ON s.id = l.sale_id WHERE l.customer_id = :c ORDER BY l.id DESC LIMIT ' . $limit,
            ['c' => $customerId]
        );
    }

    public function addLedger(array $f): int
    {
        $this->execute(
            'INSERT INTO customer_ledger (customer_id, type, amount_usd, currency, amount_original, exchange_rate, sale_id, return_id, session_id, user_id, note)
             VALUES (:c, :t, :a, :cur, :orig, :r, :s, :ret, :ses, :u, :n)',
            [
                'c' => $f['customer_id'], 't' => $f['type'], 'a' => $f['amount_usd'], 'cur' => $f['currency'] ?? null,
                'orig' => $f['amount_original'] ?? null, 'r' => $f['exchange_rate'] ?? null, 's' => $f['sale_id'] ?? null,
                'ret' => $f['return_id'] ?? null, 'ses' => $f['session_id'] ?? null, 'u' => $f['user_id'] ?? null, 'n' => $f['note'] ?? null,
            ]
        );

        return $this->lastId();
    }

    /** Customers who owe money, largest first (credit report). */
    public function debtors(): array
    {
        return array_values(array_filter($this->all(), static fn (array $c): bool => (float) $c['balance_usd'] > 0.004));
    }
}
