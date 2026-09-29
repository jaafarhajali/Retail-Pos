<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Models\Register;

/**
 * An open cash session stays enterable by its owner until it is counted and closed,
 * whatever happened to the sign-in in between (sign-out, idle timeout, a closed browser, a lost device link).
 */
$cashierOnTill = static function (): array {
    $registerId = (new Register())->create('Register 01');
    (new \App\Models\User())->setPin(TEST_ADMIN_ID, '2468');
    make_user('sara');
    $admin = login_as('admin', TEST_ADMIN_PASSWORD);
    $admin->get('registers');
    $admin->post('registers/bind', ['id' => $registerId]);

    $client = new HttpClient();
    $client->setCookie(\App\Core\RegisterDevice::COOKIE, (string) $admin->cookie(\App\Core\RegisterDevice::COOKIE));   // the same PC
    $client->get('auth/login');
    assert_same(302, $client->post('auth/login', ['username' => 'sara', 'password' => 'password123'])->status);
    $client->get('sessions/open');
    assert_same(302, $client->post('sessions/open', ['opening_usd' => '50', 'opening_lbp' => '0'])->status);
    assert_contains('pos-body', $client->get('pos')->body, 'the till opens for its cashier');

    return [$client, $registerId];
};
$signIn = static function (HttpClient $client, string $username, string $password): void {
    $client->get('auth/login');
    assert_same(302, $client->post('auth/login', ['username' => $username, 'password' => $password])->status);
};

return [
    '__before' => 'test_db_reset',

    'after signing out and in again the cashier is back in the same session' => function () use ($cashierOnTill, $signIn): void {
        [$client] = $cashierOnTill();
        $client->get('dashboard');
        $client->post('auth/logout', []);
        assert_same(302, $client->get('pos')->status, 'signed out: the till asks to sign in');
        $signIn($client, 'sara', 'password123');
        $till = $client->get('pos')->body;
        assert_contains('pos-body', $till);
        assert_contains('S-000001', $till);
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM cash_sessions WHERE status = 'open'")->fetchColumn(), 'no second session was needed');
    },

    'after the sign-in expired (idle, or the browser was closed) the cashier is back in the same session' => function () use ($cashierOnTill, $signIn): void {
        [$client] = $cashierOnTill();
        $client->forgetCookie('rpos_session');   // what a closed browser or an expired sign-in leaves: only the device link
        assert_same(302, $client->get('pos')->status);
        $signIn($client, 'sara', 'password123');
        $till = $client->get('pos')->body;
        assert_contains('pos-body', $till);
        assert_contains('S-000001', $till);
    },

    'eight hours without a request sign the user out; less does not' => function () use ($cashierOnTill, $signIn): void {
        [$client] = $cashierOnTill();
        $file = STORAGE_PATH . '/sessions/sess_' . $client->cookie('rpos_session');
        assert_true(is_file($file), 'the sign-in is stored in storage/sessions');
        $age = static function (int $seconds) use ($file): void {
            $data = (string) file_get_contents($file);
            $data = preg_replace('/last_seen\|i:\d+;/', 'last_seen|i:' . (time() - $seconds) . ';', $data, 1, $n);
            assert_same(1, $n, 'last_seen found in the session file');
            file_put_contents($file, $data);
        };
        $age(SESSION_IDLE_SECONDS - 60);
        assert_contains('pos-body', $client->get('pos')->body, 'just under the limit: still signed in');
        $age(SESSION_IDLE_SECONDS + 60);
        assert_same(302, $client->get('pos')->status, 'over the limit: signed out');
        $signIn($client, 'sara', 'password123');
        assert_contains('pos-body', $client->get('pos')->body, 'and back in the same session after signing in');
    },

    'a till request made after the sign-in ended is answered in words, as JSON' => function () use ($cashierOnTill): void {
        [$client] = $cashierOnTill();
        $client->forgetCookie('rpos_session');
        $r = $client->postJson('pos/complete', ['lines' => [], 'payments' => []]);
        assert_same(401, $r->status);
        $j = json_decode($r->body, true);
        assert_true(is_array($j) && ($j['signed_out'] ?? false) === true, 'JSON, not the sign-in page: ' . substr($r->body, 0, 80));
        assert_contains('sign in again', strtolower((string) $j['error']));
        assert_same(401, $client->postJson('pos/check', ['lines' => []])->status);
        $data = $client->get('pos/data');
        assert_same(302, $data->status, 'a page request still goes to the sign-in page');
    },

    'a lost device link is repaired by the administrator and the session goes on' => function () use ($cashierOnTill): void {
        [$client, $registerId] = $cashierOnTill();
        $client->forgetCookie(\App\Core\RegisterDevice::COOKIE);   // browser data cleared, another browser, another address
        $blocked = $client->get('pos')->body;
        assert_contains('This device is not a register', $blocked);
        assert_contains('Your session S-000001 is open on', $blocked, 'it says where the cashier\'s session is');
        assert_same(302, $client->post('registers/link', ['register_id' => $registerId, 'admin_username' => 'admin', 'admin_password' => TEST_ADMIN_PASSWORD])->status);
        $till = $client->get('pos')->body;
        assert_contains('pos-body', $till);
        assert_contains('S-000001', $till);
    },

    'a cashier whose session is on another register is told where, instead of a dead end' => function () use ($cashierOnTill): void {
        [$client] = $cashierOnTill();
        $two = (new Register())->create('Register 02');
        $other = login_as('admin', TEST_ADMIN_PASSWORD);   // another PC, linked to Register 02
        $other->get('registers');
        $other->post('registers/bind', ['id' => $two]);
        $client->setCookie(\App\Core\RegisterDevice::COOKIE, (string) $other->cookie(\App\Core\RegisterDevice::COOKIE));   // the cashier sits at that PC

        $blocked = $client->get('pos')->body;
        assert_not_contains('pos-body', $blocked);
        assert_contains('Your session S-000001 is open on', $blocked);
        assert_contains('Register 01', $blocked);
        assert_not_contains('Open a session', $blocked, 'opening a second one is impossible, so it is not offered');
        $open = $client->get('sessions/open')->body;
        assert_contains('Your session <code>S-000001</code> is open on', $open);
        assert_not_contains('name="opening_usd"', $open);
    },
];
