<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Role extends Model
{
    public const ADMIN_ID = 1;
    public const CASHIER_ID = 2;

    private const SELECT = 'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
                            FROM roles r';

    public function all(): array
    {
        return $this->fetchAll(self::SELECT . ' ORDER BY r.is_super DESC, r.name');
    }

    /** @return array<string, mixed>|null with user_count */
    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE r.id = :id', ['id' => $id]);
    }

    public function nameExists(string $name): bool
    {
        return (bool) $this->fetchValue('SELECT COUNT(*) FROM roles WHERE name = :n', ['n' => $name]);
    }

    public function create(string $name): int
    {
        $this->execute('INSERT INTO roles (name) VALUES (:n)', ['n' => $name]);

        return $this->lastId();
    }

    /** System roles are never deleted, even by mistake. */
    public function delete(int $id): void
    {
        $this->execute('DELETE FROM roles WHERE id = :id AND is_system = 0', ['id' => $id]);
    }
}
