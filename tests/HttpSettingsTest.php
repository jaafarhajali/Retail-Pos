<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

return [
    '__before' => 'test_db_reset',

    'a cashier cannot open settings or the exchange rate' => function (): void {
        make_user('cashier1');
        $client = login_as('cashier1', 'password123');
        assert_same(403, $client->get('settings')->status);
        assert_same(403, $client->get('rates')->status);
    },

    // Review focus 5
    'an admin sets the rate typed with a thousands separator' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('rates');
        assert_same(302, $client->post('rates/store', ['rate' => '89,500'])->status);
        assert_contains('1 USD = 89,500 LBP', $client->get('dashboard')->body);
    },

    'an admin saves settings and the new shop name shows in the sidebar' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('settings');
        $response = $client->post('settings/save', [
            'shop_name' => 'معسل الشام', 'shop_address' => '', 'shop_phone' => '',
            'receipt_header' => '', 'receipt_footer' => 'Thank you!',
            'lbp_rounding_step' => '5000', 'max_cashier_discount_pct' => '0',
            'usd_denominations' => '100,50,20,10,5,1', 'lbp_denominations' => '100000,50000,20000,10000,5000,1000',
        ]);
        assert_same(302, $response->status);
        assert_contains('معسل الشام', $client->get('dashboard')->body);
    },
];
