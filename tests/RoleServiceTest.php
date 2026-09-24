<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Models\Permission;
use App\Models\Role;
use App\Services\RoleService;

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a role name can be used only once (any letter case)' => function (): void {
        (new RoleService())->create('Supervisor');
        assert_throws(DomainException::class, fn () => (new RoleService())->create('supervisor'));
        assert_throws(DomainException::class, fn () => (new RoleService())->create('   '));
    },

    'permissions are replaced and unknown keys are ignored' => function (): void {
        $id = (new RoleService())->create('Supervisor');
        (new RoleService())->setPermissions($id, ['pos.use', 'report.sales', 'made.up']);
        $granted = (new Permission())->forRole($id);
        sort($granted);
        assert_same(['pos.use', 'report.sales'], $granted);
    },

    'the admin role permissions cannot be edited' => function (): void {
        assert_throws(DomainException::class, fn () => (new RoleService())->setPermissions(Role::ADMIN_ID, []));
    },

    'system roles cannot be deleted' => function (): void {
        assert_throws(DomainException::class, fn () => (new RoleService())->delete(Role::CASHIER_ID));
    },

    'a role that still has users cannot be deleted' => function (): void {
        $id = (new RoleService())->create('Temp');
        make_user('temp1', $id);
        assert_throws(DomainException::class, fn () => (new RoleService())->delete($id));
    },

    'an unused custom role can be deleted' => function (): void {
        $id = (new RoleService())->create('Temp');
        (new RoleService())->delete($id);
        assert_same(null, (new Role())->find($id));
    },

    'a role manager cannot change the permissions of their own role' => function (): void {
        $roleId = (new RoleService())->create('Manager');
        (new RoleService())->setPermissions($roleId, ['role.manage']);
        Auth::login(make_user('manager1', $roleId));
        $e = assert_throws(DomainException::class, fn () => (new RoleService())->setPermissions($roleId, ['role.manage', 'user.manage']));
        assert_contains('your own role', $e->getMessage());
        assert_same(['role.manage'], (new Permission())->forRole($roleId));
        $other = (new RoleService())->create('Other');
        (new RoleService())->setPermissions($other, ['pos.use']);   // other roles are still editable
        assert_same(['pos.use'], (new Permission())->forRole($other));
    },
];
