<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;

return [
    '__before' => 'test_db_reset',

    'a cashier cannot open or post to categories' => function (): void {
        make_user('cashier1');
        $client = login_as('cashier1', 'password123');
        assert_same(403, $client->get('categories')->status);
        $client->get('dashboard');
        assert_same(403, $client->post('categories/store', ['name' => 'Hack'])->status);
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM categories')->fetchColumn());
    },

    'an admin creates a category and sees it in the list with its colour' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('categories');
        $response = $client->post('categories/store', ['name' => 'فحم', 'color' => '#e67e22', 'sort_order' => '10']);
        assert_same(302, $response->status);
        $body = $client->get('categories')->body;
        assert_contains('فحم', $body);
        assert_contains('#e67e22', $body);
        assert_contains('r=categories', $body, 'sidebar link present for the admin');
    },

    'a duplicate name or a tampered array comes back as a form error, not a 500' => function (): void {
        make_category('Tobacco');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('categories');
        $response = $client->post('categories/store', ['name' => ['tobacco'], 'color' => '', 'sort_order' => '']);
        assert_same(302, $response->status);
        assert_same(200, $client->get('categories')->status);
        $response = $client->post('categories/store', ['name' => 'tobacco', 'color' => '', 'sort_order' => '']);
        assert_same(302, $response->status);
        assert_contains('already exists', $client->get('categories')->body);
    },
];
