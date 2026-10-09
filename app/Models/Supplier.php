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

    /** With a purchase, a payment or a ledger line, a supplier is deactivated, never deleted. */
    public function isUsed(int $id): bool
    {
        return (bool) $this->fetchValue(
            'SELECT EXISTS(SELECT 1 FROM purchases WHERE supplier_id = :a) OR EXISTS(SELECT 1 FROM supplier_ledger WHERE supplier_id = :b) OR EXISTS(SELECT 1 FROM expenses WHERE supplier_id = :c)',
            ['a' => $id, 'b' => $id, 'c' => $id]
        );
    }

    /** The ids among $ids with a purchase, a payment or an expense (one query for the list). @return array<int, true> */
    public function usedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) { return []; }
        $in = implode(',', $ids);
        $rows = $this->fetchAll("SELECT supplier_id FROM purchases WHERE supplier_id IN ($in)
            UNION SELECT supplier_id FROM supplier_ledger WHERE supplier_id IN ($in)
            UNION SELECT supplier_id FROM expenses WHERE supplier_id IN ($in)");
        return array_fill_keys(array_map(fn ($r) => (int) $r['supplier_id'], $rows), true);
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM suppliers WHERE id = :id', ['id' => $id]);
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
