<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Gate;
use App\Models\Permission;
use App\Models\Role;

/** Role rules. Throws \DomainException carrying the message to show. */
final class RoleService
{
    private Role $roles;
    private Permission $permissions;

    public function __construct()
    {
        $this->roles = new Role();
        $this->permissions = new Permission();
    }

    public function create(string $name): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 50) {
            throw new \DomainException('Role name must be 1–50 characters.');
        }
        if ($this->roles->nameExists($name)) {
            throw new \DomainException('A role with that name already exists.');
        }
        $id = $this->roles->create($name);
        Audit::log('role.created', 'role', $id, ['name' => $name]);

        return $id;
    }

    /** @param array<int, mixed> $keys unknown or non-string keys are ignored */
    public function setPermissions(int $roleId, array $keys): void
    {
        $role = $this->roles->find($roleId) ?? throw new \DomainException('Role not found.');
        if ((int) $role['is_super'] === 1) {
            throw new \DomainException('The ' . $role['name'] . ' role always has every permission.');
        }
        // Otherwise anyone with role.manage could grant their own role everything.
        $actor = Auth::user();
        if ($actor !== null && (int) $actor['is_super'] !== 1 && (int) $actor['role_id'] === $roleId) {
            throw new \DomainException('You cannot change the permissions of your own role.');
        }
        $wanted = array_filter($keys, 'is_string');
        $valid = array_values(array_intersect($this->permissions->keys(), $wanted));
        $this->permissions->setForRole($roleId, $valid);
        Audit::log('role.permissions_changed', 'role', $roleId, ['granted' => $valid]);
        Gate::forget();
    }

    public function delete(int $roleId): void
    {
        $role = $this->roles->find($roleId) ?? throw new \DomainException('Role not found.');
        if ((int) $role['is_system'] === 1) {
            throw new \DomainException('System roles cannot be deleted.');
        }
        if ((int) $role['user_count'] > 0) {
            throw new \DomainException('This role is assigned to users. Move them to another role first.');
        }
        $this->roles->delete($roleId);
        Audit::log('role.deleted', 'role', $roleId, ['name' => $role['name']]);
    }
}
