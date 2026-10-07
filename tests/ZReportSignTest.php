<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Models\Register;
use App\Services\CashService;
use App\Services\SettingService;

/**
 * 2026-10-07 (Aya, U20 + U6): the Z report is the proof of who is responsible for a difference, so it prints who
 * counted the drawer and leaves a line to sign for the cashier and for the one who counted. Like the receipt, the
 * X and Z reports carry the shop's address and phone.
 */
$shop = static function (): void {
    (new SettingService())->save([
        'shop_name' => 'Argile House', 'shop_address' => 'Hamra Street, Beirut', 'shop_phone' => '+961 1 234 567',
        'receipt_header' => '', 'receipt_footer' => '', 'lbp_rounding_step' => '5000', 'max_cashier_discount_pct' => '0',
        'usd_denominations' => '100,50,20,10,5,1', 'lbp_denominations' => '100000,50000,20000,10000,5000,1000',
    ]);
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the Z report says who counted and has two lines to sign (sara sold, admin counted)' => function () use ($shop): void {
        $shop();
        $sara = make_user('sara');
        $session = (new CashService())->open((new Register())->create('Register 01'), $sara, '50', '0');
        (new CashService())->close($session, ['50' => '1'], [], TEST_ADMIN_ID, true);

        $z = login_as('admin', TEST_ADMIN_PASSWORD)->get('sessions/print', ['id' => $session])->body;
        assert_contains('Z REPORT', $z);
        assert_contains('Cashier sara', $z);
        assert_contains('Counted by admin', $z);
        assert_contains('Cashier signature: ____________', $z);
        assert_contains('Counted by signature: ____________', $z);
        assert_contains('Hamra Street, Beirut', $z);
        assert_contains('+961 1 234 567', $z);
    },

    'a cashier who counts their own drawer is named too, with one line to sign' => function (): void {
        $session = (new CashService())->open((new Register())->create('Register 01'), TEST_ADMIN_ID, '50', '0');
        (new CashService())->close($session, ['50' => '1'], [], TEST_ADMIN_ID);

        $z = login_as('admin', TEST_ADMIN_PASSWORD)->get('sessions/print', ['id' => $session])->body;
        assert_contains('Counted by admin', $z);
        assert_contains('Cashier signature: ____________', $z);
        assert_not_contains('Counted by signature', $z, 'the same person signs once');
    },

    'the X report has the address and phone but nothing to sign: nobody has counted yet' => function () use ($shop): void {
        $shop();
        $session = (new CashService())->open((new Register())->create('Register 01'), TEST_ADMIN_ID, '50', '0');

        $x = login_as('admin', TEST_ADMIN_PASSWORD)->get('sessions/print', ['id' => $session])->body;
        assert_contains('X REPORT', $x);
        assert_contains('Hamra Street, Beirut', $x);
        assert_not_contains('Counted by', $x);
        assert_not_contains('signature', $x);
    },
];
