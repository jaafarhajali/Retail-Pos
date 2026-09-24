<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;

return [
    '__before' => 'test_db_reset',

    'guests are redirected to the login page' => function (): void {
        $response = (new HttpClient())->get('dashboard');
        assert_same(302, $response->status);
        assert_contains('r=auth/login', $response->location());
    },

    'the login page renders a CSRF token' => function (): void {
        $client = new HttpClient();
        assert_same(200, $client->get('auth/login')->status);
        assert_same(64, strlen($client->token()));
    },

    'posting without the CSRF token is refused with 419' => function (): void {
        $client = new HttpClient();
        $client->get('auth/login');
        $response = $client->post('auth/login', ['username' => 'admin', 'password' => TEST_ADMIN_PASSWORD], false);
        assert_same(419, $response->status);
    },

    'correct credentials open the dashboard' => function (): void {
        $response = login_as('admin', TEST_ADMIN_PASSWORD)->get('dashboard');
        assert_same(200, $response->status);
        assert_contains('Welcome', $response->body);
    },

    'a wrong password shows an error and stays signed out' => function (): void {
        $client = new HttpClient();
        $client->get('auth/login');
        $client->post('auth/login', ['username' => 'admin', 'password' => 'wrong-password']);
        assert_contains('Invalid username or password.', $client->get('auth/login')->body);
        assert_same(302, $client->get('dashboard')->status);
    },

    // Review focus 3
    'tampered array fields do not crash the login' => function (): void {
        $client = new HttpClient();
        $client->get('auth/login');
        $response = $client->post('auth/login', ['username' => ['admin'], 'password' => ['x']]);
        assert_same(302, $response->status);
        assert_contains('r=auth/login', $response->location());
    },

    // Review focus 4
    'the lockout survives new browsers (fresh cookies)' => function (): void {
        for ($i = 0; $i < 5; $i++) {
            $attacker = new HttpClient();
            $attacker->get('auth/login');
            $attacker->post('auth/login', ['username' => 'admin', 'password' => 'guess-' . $i]);
        }
        $client = new HttpClient();
        $client->get('auth/login');
        $client->post('auth/login', ['username' => 'admin', 'password' => TEST_ADMIN_PASSWORD]);
        assert_contains('Too many failed attempts', $client->get('auth/login')->body);
    },

    'a forced password change blocks other pages until done' => function (): void {
        Database::pdo()->exec("UPDATE users SET must_change_password = 1 WHERE username = 'admin'");
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $response = $client->get('dashboard');
        assert_same(302, $response->status);
        assert_contains('r=auth/password', $response->location());

        $client->get('auth/password');
        $response = $client->post('auth/password', [
            'current_password' => TEST_ADMIN_PASSWORD,
            'new_password'     => 'brand-new-pass-1',
            'confirm_password' => 'brand-new-pass-1',
        ]);
        assert_contains('r=dashboard', $response->location());
        assert_same(200, $client->get('dashboard')->status);
    },

    'signing out ends the session' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('dashboard');
        $client->post('auth/logout', []);
        assert_same(302, $client->get('dashboard')->status);
    },

    // Review focus 1
    'arabic full names render unchanged' => function (): void {
        Database::pdo()->exec("UPDATE users SET full_name = 'أحمد الخطيب' WHERE username = 'admin'");
        assert_contains('أحمد الخطيب', login_as('admin', TEST_ADMIN_PASSWORD)->get('dashboard')->body);
    },
];
