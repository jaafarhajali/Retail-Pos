<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Parked carts on a register (POS "hold"). */
final class HeldSale extends Model
{
    public function forRegister(int $registerId): array
    {
        return $this->fetchAll('SELECT h.*, u.username FROM held_sales h JOIN users u ON u.id = h.user_id WHERE h.register_id = :r ORDER BY h.id DESC', ['r' => $registerId]);
    }

    public function find(int $id): ?array
    {
        return $this->fetch('SELECT * FROM held_sales WHERE id = :id', ['id' => $id]);
    }

    public function create(int $registerId, int $userId, string $name, string $cartJson): int
    {
        $this->execute('INSERT INTO held_sales (register_id, user_id, name, cart_json) VALUES (:r, :u, :n, :c)', ['r' => $registerId, 'u' => $userId, 'n' => $name, 'c' => $cartJson]);

        return $this->lastId();
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM held_sales WHERE id = :id', ['id' => $id]);
    }
}
