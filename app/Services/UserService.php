<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Models\Role;
use App\Models\User;

/** User account rules. Throws \DomainException carrying the message to show. */
final class UserService
{
    private User $users;
    private Role $roles;

    public function __construct()
    {
        $this->users = new User();
        $this->roles = new Role();
    }

    public function create(string $username, string $fullName, int $roleId, string $password): int
    {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            throw new \DomainException('Username must be 3–50 characters: letters, digits, dot, dash or underscore.');
        }
        if ($this->users->usernameExists($username)) {
            throw new \DomainException('That username is already taken.');
        }
        $fullName = $this->cleanName($fullName);
        $this->assertMayAssign($this->findRole($roleId));
        $this->assertPassword($password);

        $id = $this->users->create($username, $password, $fullName, $roleId, true);
        Audit::log('user.created', 'user', $id, ['username' => $username, 'role_id' => $roleId]);

        return $id;
    }

    public function update(int $id, string $fullName, int $roleId, bool $active): void
    {
        $user = $this->users->find($id) ?? throw new \DomainException('User not found.');
        $this->assertMayTouch($user);
        $fullName = $this->cleanName($fullName);
        $role = $this->findRole($roleId);
        $this->assertMayAssign($role);

        if ($id === Auth::id() && !$active) {
            throw new \DomainException('You cannot deactivate your own account.');
        }
        if ($id === Auth::id() && $roleId !== (int) $user['role_id'] && !$this->actorIsSuper()) {
            throw new \DomainException('You cannot change your own role.');
        }
        $isActiveAdmin = (int) $user['is_super'] === 1 && (int) $user['is_active'] === 1;
        $staysActiveAdmin = $active && (int) $role['is_super'] === 1;
        if ($isActiveAdmin && !$staysActiveAdmin && $this->users->countActiveSuperAdmins($id) === 0) {
            throw new \DomainException('This is the last active administrator. Create or activate another administrator first.');
        }

        $this->users->update($id, $fullName, $roleId, $active);
        Audit::log('user.updated', 'user', $id, ['full_name' => $fullName, 'role_id' => $roleId, 'is_active' => $active]);
    }

    public function resetPassword(int $id, string $password): void
    {
        $this->assertMayTouch($this->users->find($id) ?? throw new \DomainException('User not found.'));
        $this->assertPassword($password);
        $this->users->setPassword($id, $password, true);
        Audit::log('user.password_reset', 'user', $id);
    }

    /** The approval PIN used at the POS. '' removes it. */
    public function setPin(int $id, string $pin): void
    {
        $this->assertMayTouch($this->users->find($id) ?? throw new \DomainException('User not found.'));
        if ($pin === '') {
            $this->users->setPin($id, null);
            Audit::log('user.pin_cleared', 'user', $id);

            return;
        }
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new \DomainException('The PIN must be 4 to 6 digits.');
        }
        $this->users->setPin($id, $pin);
        Audit::log('user.pin_set', 'user', $id);
    }

    private function actorIsSuper(): bool
    {
        return (int) (Auth::user()['is_super'] ?? 0) === 1;
    }

    /**
     * Someone who merely holds user.manage (a supervisor) must not be able to become an
     * administrator or take over one: only administrators give the Admin role or touch
     * Admin accounts (edit, password reset, approval PIN).
     */
    private function assertMayAssign(array $role): void
    {
        if ((int) $role['is_super'] === 1 && !$this->actorIsSuper()) {
            throw new \DomainException('Only an administrator can give the ' . $role['name'] . ' role.');
        }
    }

    private function assertMayTouch(array $user): void
    {
        if ((int) $user['is_super'] === 1 && !$this->actorIsSuper()) {
            throw new \DomainException('Only an administrator can change an administrator account.');
        }
    }

    private function cleanName(string $fullName): string
    {
        $fullName = trim($fullName);
        if ($fullName === '' || mb_strlen($fullName) > 100) {
            throw new \DomainException('Full name must be 1–100 characters.');
        }

        return $fullName;
    }

    private function findRole(int $roleId): array
    {
        return $this->roles->find($roleId) ?? throw new \DomainException('Choose a valid role.');
    }

    private function assertPassword(string $password): void
    {
        if (mb_strlen($password) < 8 || mb_strlen($password) > 255) {
            throw new \DomainException('The password must be 8–255 characters.');
        }
    }
}
