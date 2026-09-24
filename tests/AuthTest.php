<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\LoginThrottle;
use App\Models\Role;
use App\Models\User;

return [
    '__before' => 'test_db_reset',

    'correct credentials sign the user in' => function (): void {
        assert_same(null, Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.1'));
        assert_true(Auth::check());
        assert_same('Admin', Auth::user()['role_name']);
    },

    'a wrong password is rejected and recorded' => function (): void {
        assert_same('Invalid username or password.', Auth::attempt('admin', 'nope', '10.0.0.1'));
        assert_false(Auth::check());
        assert_same(0, (int) Database::pdo()->query('SELECT success FROM login_attempts ORDER BY id DESC LIMIT 1')->fetchColumn());
    },

    'five failures lock that username on that IP, even with the right password' => function (): void {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            Auth::attempt('admin', 'nope', '10.0.0.1');
        }
        assert_contains('Too many failed attempts', (string) Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.1'));
        assert_false(Auth::check());
        assert_same(null, Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.2'), 'another IP is not locked');
    },

    'a successful sign-in resets the failure count' => function (): void {
        for ($i = 0; $i < 4; $i++) {
            Auth::attempt('admin', 'nope', '10.0.0.1');
        }
        assert_same(null, Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.1'));
        Auth::logout();
        for ($i = 0; $i < 4; $i++) {
            Auth::attempt('admin', 'nope', '10.0.0.1');
        }
        assert_same(null, Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.1'));
    },

    'twenty failures from one IP lock every username on it' => function (): void {
        make_user('cashier1');
        for ($i = 0; $i < LoginThrottle::MAX_IP_FAILURES; $i++) {
            Auth::attempt('guess' . $i, 'nope', '10.0.0.9');
        }
        assert_contains('Too many failed attempts', (string) Auth::attempt('cashier1', 'password123', '10.0.0.9'));
    },

    'a deactivated user cannot sign in' => function (): void {
        $id = make_user('cashier1');
        (new User())->update($id, 'Cashier 1', Role::CASHIER_ID, false);
        assert_same('Invalid username or password.', Auth::attempt('cashier1', 'password123', '10.0.0.1'));
    },

    'deactivating a signed-in user signs them out on their next request' => function (): void {
        $id = make_user('cashier1');
        assert_same(null, Auth::attempt('cashier1', 'password123', '10.0.0.1'));
        (new User())->update($id, 'Cashier 1', Role::CASHIER_ID, false);
        Auth::forget();   // what the next request does
        assert_false(Auth::check());
        assert_false(isset($_SESSION['user_id']));
    },

    'logout clears the whole session' => function (): void {
        Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.1');
        Auth::logout();
        assert_false(Auth::check());
        assert_same([], $_SESSION);
    },
];
