<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Models\Role;

return [
    '__before' => 'test_db_reset',

    'a cashier is refused admin pages and the refusal is audited' => function (): void {
        make_user('cashier1');
        $response = login_as('cashier1', 'password123')->get('users');
        assert_same(403, $response->status);
        $denied = (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'access.denied'")->fetchColumn();
        assert_same(1, $denied);
    },

    'a cashier cannot post to admin actions even with a valid token' => function (): void {
        make_user('cashier1');
        $client = login_as('cashier1', 'password123');
        $client->get('dashboard');
        $response = $client->post('users/store', [
            'username' => 'intruder', 'full_name' => 'X', 'role_id' => Role::ADMIN_ID, 'password' => 'intruder-pass',
        ]);
        assert_same(403, $response->status);
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE username = 'intruder'")->fetchColumn());
    },

    'the cashier sidebar hides admin links' => function (): void {
        make_user('cashier1');
        $body = login_as('cashier1', 'password123')->get('dashboard')->body;
        assert_not_contains('r=users', $body);
        assert_not_contains('r=roles', $body);
    },

    'an admin creates a cashier through the form' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('users/create');
        $response = $client->post('users/store', [
            'username' => 'ali', 'full_name' => 'علي', 'role_id' => Role::CASHIER_ID, 'password' => 'temp-pass-1',
        ]);
        assert_same(302, $response->status);
        assert_contains('r=users/edit', $response->location());
        $row = Database::pdo()->query("SELECT full_name, must_change_password FROM users WHERE username = 'ali'")->fetch();
        assert_same('علي', $row['full_name']);
        assert_same(1, (int) $row['must_change_password']);
    },

    'a new cashier must replace the temporary password first' => function (): void {
        $admin = login_as('admin', TEST_ADMIN_PASSWORD);
        $admin->get('users/create');
        $admin->post('users/store', ['username' => 'ali', 'full_name' => 'Ali', 'role_id' => Role::CASHIER_ID, 'password' => 'temp-pass-1']);
        $response = login_as('ali', 'temp-pass-1')->get('dashboard');
        assert_same(302, $response->status);
        assert_contains('r=auth/password', $response->location());
    },
];
