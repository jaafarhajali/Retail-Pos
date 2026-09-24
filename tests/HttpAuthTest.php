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

    // A missing/stale token while signed out means the session ended (idle timeout, overnight
    // login tab, sign-out in another tab): send the person back to sign in, never a 419 page.
    'a guest posting without a valid CSRF token is sent back to sign in' => function (): void {
        $client = new HttpClient();
        $client->get('auth/login');
        $response = $client->post('auth/login', ['username' => 'admin', 'password' => TEST_ADMIN_PASSWORD], false);
        assert_same(302, $response->status);
        assert_contains('r=auth/login', $response->location());
        assert_contains('session ended', $client->get('auth/login')->body);
        assert_same(302, $client->get('dashboard')->status, 'still signed out');
    },

    'a signed-in user posting a stale CSRF token is refused with 419' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('dashboard');
        assert_same(419, $client->post('auth/logout', ['_token' => 'stale'], false)->status);
        assert_same(200, $client->get('dashboard')->status, 'still signed in');
    },

    'sessions are stored under storage/sessions, not the shared XAMPP temp folder' => function (): void {
        array_map('unlink', glob(STORAGE_PATH . '/sessions/sess_*') ?: []);
        (new HttpClient())->get('auth/login');
        assert_true(count(glob(STORAGE_PATH . '/sessions/sess_*') ?: []) >= 1, 'a session file should exist under storage/sessions');
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
