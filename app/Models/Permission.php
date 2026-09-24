<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Permission extends Model
{
    public function all(): array
    {
        return $this->fetchAll('SELECT * FROM permissions ORDER BY sort_order');
    }

    /** @return string[] every known permission key, in display order */
    public function keys(): array
    {
        return array_column($this->all(), 'perm_key');
    }

    /** @return string[] keys granted to the role */
    public function forRole(int $roleId): array
    {
        return array_column(
            $this->fetchAll('SELECT perm_key FROM role_permissions WHERE role_id = :r', ['r' => $roleId]),
            'perm_key'
        );
    }

    /** Replace the role's permissions. The caller passes only known keys. */
    public function setForRole(int $roleId, array $keys): void
    {
        Database::transaction(function () use ($roleId, $keys): void {
            $this->execute('DELETE FROM role_permissions WHERE role_id = :r', ['r' => $roleId]);
            foreach ($keys as $key) {
                $this->execute(
                    'INSERT INTO role_permissions (role_id, perm_key) VALUES (:r, :k)',
                    ['r' => $roleId, 'k' => $key]
                );
            }
        });
    }
}
