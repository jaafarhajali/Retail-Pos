<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Register;
use App\Services\CashService;

/**
 * 2026-10-04 (Aya): debt collected at the till can be paid in USD, LBP or both, and a customer who hands over more
 * than he owes gets change, in LBP (rounded to 5,000) or USD, shown before Collect.
 */
$setup = static function (string $owes): array {
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '1000000');
    $customer = (new Customer())->create(['name' => 'Lounge 961', 'phone' => '76 555 111', 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);
    (new Customer())->addLedger(['customer_id' => $customer, 'type' => 'sale_credit', 'amount_usd' => $owes, 'note' => 'INV-000016']);

    return ['register' => $register, 'session' => $session, 'customer' => $customer];
};
$collect = static fn (array $s, string $usd, string $lbp, string $change = 'LBP', bool $allow = false): array =>
    (new CashService())->collectDebt($s['customer'], $usd, $lbp, $s['session'], TEST_ADMIN_ID, $change, $allow);
$drawer = static fn (array $s): array => (new CashSession())->expected($s['session']);
$owes = static fn (array $s): string => (new Customer())->balance($s['customer']);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'part of the debt in USD: he still owes the rest' => function () use ($setup, $collect, $drawer, $owes): void {
        $s = $setup('8.00');
        $r = $collect($s, '5', '');
        assert_same(['paid_usd' => '5.00', 'change_usd' => '0.00', 'change_lbp' => 0, 'balance' => '3.00'], $r);
        assert_same(['USD' => '105.00', 'LBP' => '1000000'], $drawer($s));
    },

    'all of it as $5 + 270,000 LBP: both currencies go into the drawer' => function () use ($setup, $collect, $drawer, $owes): void {
        $s = $setup('8.00');
        $collect($s, '5', '270,000');
        assert_same('0.00', $owes($s));
        assert_same(['USD' => '105.00', 'LBP' => '1270000'], $drawer($s));
    },

    'owes $8 and hands over $10: 180,000 LBP of change, the debt is cleared' => function () use ($setup, $collect, $drawer, $owes): void {
        $s = $setup('8.00');
        $r = $collect($s, '10', '', 'LBP');
        assert_same(['paid_usd' => '8.00', 'change_usd' => '0.00', 'change_lbp' => 180000, 'balance' => '0.00'], $r);
        assert_same(['USD' => '110.00', 'LBP' => '820000'], $drawer($s));
    },

    'change in USD: whole dollars, the cents in LBP (1,000,000 LBP for $8 → $3 + 10,000 LBP)' => function () use ($setup, $collect, $drawer): void {
        $s = $setup('8.00');
        $r = $collect($s, '', '1,000,000', 'USD');
        assert_same('3.00', $r['change_usd']);
        assert_same(10000, $r['change_lbp']);   // $0.11 = 9,900 → 10,000
        assert_same(['USD' => '97.00', 'LBP' => '1990000'], $drawer($s));
    },

    'within half a 5,000 note of the debt counts as paid ($7.24 owed, 650,000 LBP given)' => function () use ($setup, $collect, $owes): void {
        $s = $setup('7.24');
        $r = $collect($s, '', '650,000');
        assert_same('0.00', $owes($s));
        assert_same(0, $r['change_lbp']);
    },

    'change the drawer cannot give is warned about, and goes through once confirmed' => function () use ($setup, $collect): void {
        $s = $setup('8.00');
        // $50 for $8: $42 = 3,780,000 LBP of change, the drawer has 1,000,000
        $e = assert_throws(DomainException::class, fn () => $collect($s, '50', '', 'LBP'));
        assert_same(CashService::SHORT_DRAWER, $e->getCode());
        assert_same(3780000, $collect($s, '50', '', 'LBP', true)['change_lbp']);
    },

    'nothing typed, or a customer who owes nothing, is refused' => function () use ($setup, $collect): void {
        $s = $setup('8.00');
        assert_contains('Type what he gives', assert_throws(DomainException::class, fn () => $collect($s, '', ''))->getMessage());
        $collect($s, '8', '');
        assert_contains('owes nothing', assert_throws(DomainException::class, fn () => $collect($s, '5', ''))->getMessage());
    },

    'the till sends both amounts and gets the change back' => function () use ($setup): void {
        $s = $setup('8.00');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $r = $client->postJson('pos/debt', ['customer_id' => $s['customer'], 'usd' => '10', 'lbp' => '', 'change_currency' => 'LBP']);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same(180000, $j['change_lbp']);
        assert_same('0.00', $j['balance']);
    },
];
