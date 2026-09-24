<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;

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
];
