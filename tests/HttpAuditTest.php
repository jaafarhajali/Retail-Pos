<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

return [
    '__before' => 'test_db_reset',

    'an admin sees sign-ins in the audit log' => function (): void {
        $response = login_as('admin', TEST_ADMIN_PASSWORD)->get('audit');
        assert_same(200, $response->status);
        assert_contains('auth.login', $response->body);
    },

    'a cashier cannot open the audit log' => function (): void {
        make_user('cashier1');
        assert_same(403, login_as('cashier1', 'password123')->get('audit')->status);
    },
];
