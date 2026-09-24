<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Register extends Model
{
    public function all(): array
    {
        return $this->fetchAll('SELECT * FROM registers ORDER BY is_active DESC, name');
    }

    public function find(int $id): ?array
    {
        return $this->fetch('SELECT * FROM registers WHERE id = :id', ['id' => $id]);
    }

    public function nameExists(string $name): bool
    {
        return (bool) $this->fetchValue('SELECT COUNT(*) FROM registers WHERE name = :n', ['n' => $name]);
    }

    public function create(string $name): int
    {
        $this->execute('INSERT INTO registers (name) VALUES (:n)', ['n' => $name]);

        return $this->lastId();
    }

    public function bind(int $id, string $tokenHash): void
    {
        $this->execute(
            'UPDATE registers SET device_token_hash = :h, bound_at = NOW() WHERE id = :id',
            ['h' => $tokenHash, 'id' => $id]
        );
    }

    public function deactivate(int $id): void
    {
        $this->execute('UPDATE registers SET is_active = 0, device_token_hash = NULL WHERE id = :id', ['id' => $id]);
    }

    public function findActiveByTokenHash(string $hash): ?array
    {
        return $this->fetch('SELECT * FROM registers WHERE device_token_hash = :h AND is_active = 1', ['h' => $hash]);
    }
}
