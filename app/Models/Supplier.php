<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Supplier extends Model
{
    private const SELECT = 'SELECT s.*, COALESCE((SELECT SUM(l.amount_usd) FROM supplier_ledger l WHERE l.supplier_id = s.id), 0) AS balance_usd FROM suppliers s';

    public function all(bool $activeOnly = false): array
    {
        return $this->fetchAll(self::SELECT . ($activeOnly ? ' WHERE s.is_active = 1' : '') . ' ORDER BY s.name');
    }

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE s.id = :id', ['id' => $id]);
    }

    public function nameExists(string $name, int $exceptId = 0): bool
    {
        return (bool) $this->fetchValue('SELECT COUNT(*) FROM suppliers WHERE name = :n AND id <> :id', ['n' => $name, 'id' => $exceptId]);
    }

    public function create(string $name, ?string $phone, ?string $notes): int
    {
        $this->execute('INSERT INTO suppliers (name, phone, notes) VALUES (:n, :p, :o)', ['n' => $name, 'p' => $phone, 'o' => $notes]);

        return $this->lastId();
    }

    public function update(int $id, string $name, ?string $phone, ?string $notes, bool $active): void
    {
        $this->execute('UPDATE suppliers SET name = :n, phone = :p, notes = :o, is_active = :a WHERE id = :id',
            ['n' => $name, 'p' => $phone, 'o' => $notes, 'a' => (int) $active, 'id' => $id]);
    }

    public function ledger(int $supplierId, int $limit = 200): array
    {
        return $this->fetchAll(
            'SELECT l.*, u.username, p.purchase_no FROM supplier_ledger l LEFT JOIN users u ON u.id = l.user_id
             LEFT JOIN purchases p ON p.id = l.purchase_id WHERE l.supplier_id = :s ORDER BY l.id DESC LIMIT ' . $limit,
            ['s' => $supplierId]
        );
    }

    public function addLedger(int $supplierId, string $type, string $amountUsd, ?int $purchaseId, ?int $expenseId, ?int $userId, ?string $note): void
    {
        $this->execute(
            'INSERT INTO supplier_ledger (supplier_id, type, amount_usd, purchase_id, expense_id, user_id, note) VALUES (:s, :t, :a, :p, :e, :u, :n)',
            ['s' => $supplierId, 't' => $type, 'a' => $amountUsd, 'p' => $purchaseId, 'e' => $expenseId, 'u' => $userId, 'n' => $note]
        );
    }
}
