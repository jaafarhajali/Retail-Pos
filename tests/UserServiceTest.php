<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;

/** Sign in as a non-admin "Manager" who holds user.manage and role.manage; returns [roleId, userId]. */
$asManager = static function (): array {
    $roleId = (new \App\Models\Role())->create('Manager');
    (new \App\Models\Permission())->setForRole($roleId, ['user.manage', 'role.manage']);
    $uid = make_user('manager1', $roleId);
    Auth::login($uid);

    return [$roleId, $uid];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a new user gets a hashed password and must change it at first sign-in' => function (): void {
        $id = (new UserService())->create('ahmad', 'Ahmad', Role::CASHIER_ID, 'temp-pass-1');
        $user = (new User())->find($id);
        assert_true(password_verify('temp-pass-1', $user['password_hash']));
        assert_same(1, (int) $user['must_change_password']);
        assert_same('Cashier', $user['role_name']);
    },

    'usernames must be 3-50 safe characters' => function (): void {
        foreach (['ab', 'with space', 'bad/char', str_repeat('a', 51), 'أحمد'] as $bad) {
            assert_throws(DomainException::class, fn () => (new UserService())->create($bad, 'X', Role::CASHIER_ID, 'temp-pass-1'));
        }
    },

    // Review focus 2
    'usernames that differ only by letter case are duplicates' => function (): void {
        (new UserService())->create('ahmad', 'Ahmad', Role::CASHIER_ID, 'temp-pass-1');
        $e = assert_throws(DomainException::class, fn () => (new UserService())->create('Ahmad', 'Other', Role::CASHIER_ID, 'temp-pass-1'));
        assert_contains('already taken', $e->getMessage());
    },

    'passwords shorter than 8 characters are refused' => function (): void {
        assert_throws(DomainException::class, fn () => (new UserService())->create('ahmad', 'Ahmad', Role::CASHIER_ID, 'short'));
    },

    'an unknown role is refused' => function (): void {
        assert_throws(DomainException::class, fn () => (new UserService())->create('ahmad', 'Ahmad', 999, 'temp-pass-1'));
    },

    // Review focus 1
    'arabic full names are stored unchanged' => function (): void {
        $id = (new UserService())->create('ahmad', 'أحمد الخطيب', Role::CASHIER_ID, 'temp-pass-1');
        assert_same('أحمد الخطيب', (new User())->find($id)['full_name']);
    },

    'you cannot deactivate your own account' => function (): void {
        $e = assert_throws(DomainException::class, fn () => (new UserService())->update(TEST_ADMIN_ID, 'Owner', Role::ADMIN_ID, false));
        assert_contains('your own account', $e->getMessage());
    },

    'the last active administrator cannot lose the admin role' => function (): void {
        $e = assert_throws(DomainException::class, fn () => (new UserService())->update(TEST_ADMIN_ID, 'Owner', Role::CASHIER_ID, true));
        assert_contains('last active administrator', $e->getMessage());
    },

    'with a second administrator the first can be demoted' => function (): void {
        make_user('admin2', Role::ADMIN_ID);
        (new UserService())->update(TEST_ADMIN_ID, 'Owner', Role::CASHIER_ID, true);
        assert_same(Role::CASHIER_ID, (int) (new User())->find(TEST_ADMIN_ID)['role_id']);
    },

    'resetting a password forces a change at next sign-in' => function (): void {
        $id = make_user('cashier1');
        (new UserService())->resetPassword($id, 'reset-pass-1');
        $user = (new User())->find($id);
        assert_true(password_verify('reset-pass-1', $user['password_hash']));
        assert_same(1, (int) $user['must_change_password']);
    },

    'a PIN must be 4-6 digits and an empty PIN removes it' => function (): void {
        $id = make_user('cashier1');
        foreach (['12', '1234567', '12a4'] as $bad) {
            assert_throws(DomainException::class, fn () => (new UserService())->setPin($id, $bad));
        }
        (new UserService())->setPin($id, '4321');
        assert_true(password_verify('4321', (string) (new User())->find($id)['pin_hash']));
        (new UserService())->setPin($id, '');
        assert_same(null, (new User())->find($id)['pin_hash']);
    },

    'user changes are written to the audit log' => function (): void {
        $id = (new UserService())->create('ahmad', 'Ahmad', Role::CASHIER_ID, 'temp-pass-1');
        $row = Database::pdo()->query("SELECT * FROM audit_log WHERE action = 'user.created' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same($id, (int) $row['entity_id']);
        assert_same(TEST_ADMIN_ID, (int) $row['user_id']);
    },

    // A non-admin role that holds user.manage (e.g. a supervisor) must not be able to become,
    // or take over, an administrator.
    'a user manager cannot assign the administrator role' => function () use ($asManager): void {
        [, $uid] = $asManager();
        $e = assert_throws(DomainException::class, fn () => (new UserService())->create('newadmin', 'X', Role::ADMIN_ID, 'temp-pass-1'));
        assert_contains('administrator', $e->getMessage());
        assert_throws(DomainException::class, fn () => (new UserService())->update($uid, 'Me', Role::ADMIN_ID, true));
    },

    'a user manager cannot edit, reset or set the PIN of an administrator' => function () use ($asManager): void {
        $asManager();
        assert_throws(DomainException::class, fn () => (new UserService())->update(TEST_ADMIN_ID, 'Owner', Role::ADMIN_ID, true));
        assert_throws(DomainException::class, fn () => (new UserService())->resetPassword(TEST_ADMIN_ID, 'hijack-pass-1'));
        assert_throws(DomainException::class, fn () => (new UserService())->setPin(TEST_ADMIN_ID, '1234'));
        assert_same(null, (new User())->find(TEST_ADMIN_ID)['pin_hash']);
    },

    'a user manager cannot change their own role but can still edit their own name' => function () use ($asManager): void {
        [$roleId, $uid] = $asManager();
        assert_throws(DomainException::class, fn () => (new UserService())->update($uid, 'Me', Role::CASHIER_ID, true));
        (new UserService())->update($uid, 'Renamed', $roleId, true);
        assert_same('Renamed', (new User())->find($uid)['full_name']);
    },
];
