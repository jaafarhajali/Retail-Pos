<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Core\RegisterDevice;

return [
    '__before' => 'test_db_reset',

    'an admin links this browser to a register' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        assert_same(302, $client->post('registers/store', ['name' => 'Register 01'])->status);
        $id = (int) Database::pdo()->query("SELECT id FROM registers WHERE name = 'Register 01'")->fetchColumn();

        $client->get('registers');
        assert_same(302, $client->post('registers/bind', ['id' => $id])->status);
        assert_true($client->cookie(RegisterDevice::COOKIE) !== null, 'device cookie set');
        assert_contains('Register 01', $client->get('dashboard')->body);
    },

    // Browsers cap cookie lifetimes at 400 days; renewing on every visit keeps a till linked for good.
    'the device cookie is renewed on every request' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/store', ['name' => 'Register 01']);
        $id = (int) Database::pdo()->query("SELECT id FROM registers WHERE name = 'Register 01'")->fetchColumn();
        $client->get('registers');
        $client->post('registers/bind', ['id' => $id]);

        $response = $client->get('dashboard');
        assert_contains(RegisterDevice::COOKIE . '=', $response->headers['set-cookie'] ?? '', 'cookie should be re-issued');
        assert_contains('expires=', strtolower($response->headers['set-cookie'] ?? ''));
    },

    'a cashier cannot manage registers' => function (): void {
        make_user('cashier1');
        assert_same(403, login_as('cashier1', 'password123')->get('registers')->status);
    },
];
