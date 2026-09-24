<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class User extends Model
{
    private const SELECT = 'SELECT u.*, r.name AS role_name, r.is_super
                            FROM users u JOIN roles r ON r.id = u.role_id';

    public function create(string $username, string $password, string $fullName, int $roleId, bool $mustChangePassword): int
    {
        $this->execute(
            'INSERT INTO users (username, password_hash, full_name, role_id, must_change_password)
             VALUES (:u, :h, :n, :r, :m)',
            [
                'u' => $username,
                'h' => password_hash($password, PASSWORD_DEFAULT),
                'n' => $fullName,
                'r' => $roleId,
                'm' => (int) $mustChangePassword,
            ]
        );

        return $this->lastId();
    }

    /** @return array<string, mixed>|null with role_name and is_super */
    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE u.id = :id', ['id' => $id]);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE u.username = :u', ['u' => $username]);
    }

    public function all(): array
    {
        return $this->fetchAll(self::SELECT . ' ORDER BY u.is_active DESC, u.username');
    }

    /** Case-insensitive, because the column collation is utf8mb4_unicode_ci. */
    public function usernameExists(string $username): bool
    {
        return (bool) $this->fetchValue('SELECT COUNT(*) FROM users WHERE username = :u', ['u' => $username]);
    }

    public function update(int $id, string $fullName, int $roleId, bool $isActive): void
    {
        $this->execute(
            'UPDATE users SET full_name = :n, role_id = :r, is_active = :a WHERE id = :id',
            ['n' => $fullName, 'r' => $roleId, 'a' => (int) $isActive, 'id' => $id]
        );
    }

    public function setPassword(int $id, string $password, bool $mustChange): void
    {
        $this->execute(
            'UPDATE users SET password_hash = :h, must_change_password = :m WHERE id = :id',
            ['h' => password_hash($password, PASSWORD_DEFAULT), 'm' => (int) $mustChange, 'id' => $id]
        );
    }

    /** null removes the PIN. */
    public function setPin(int $id, ?string $pin): void
    {
        $this->execute(
            'UPDATE users SET pin_hash = :h WHERE id = :id',
            ['h' => $pin === null ? null : password_hash($pin, PASSWORD_DEFAULT), 'id' => $id]
        );
    }

    public function touchLogin(int $id): void
    {
        $this->execute('UPDATE users SET last_login_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    public function countActiveSuperAdmins(int $exceptId = 0): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1 AND r.is_super = 1 AND u.id <> :id',
            ['id' => $exceptId]
        );
    }
}
