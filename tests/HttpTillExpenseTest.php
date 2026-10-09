<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Models\CashSession;
use App\Models\Register;

/** Owner, 2026-10-10: a cashier pays a small expense from his drawer at the till, with the administrator's PIN. */
$cashierOnTill = static function (): HttpClient {
    $registerId = (new Register())->create('Register 01');
    (new \App\Models\User())->setPin(TEST_ADMIN_ID, '2468');
    make_user('sara');
    $admin = login_as('admin', TEST_ADMIN_PASSWORD);
    $admin->get('registers');
    $admin->post('registers/bind', ['id' => $registerId]);
    $client = new HttpClient();
    $client->setCookie(\App\Core\RegisterDevice::COOKIE, (string) $admin->cookie(\App\Core\RegisterDevice::COOKIE));
    $client->get('auth/login');
    assert_same(302, $client->post('auth/login', ['username' => 'sara', 'password' => 'password123'])->status);
    $client->get('sessions/open');
    assert_same(302, $client->post('sessions/open', ['opening_usd' => '50', 'opening_lbp' => '1,000,000'])->status);
    $client->get('pos');

    return $client;
};

return [
    '__before' => 'test_db_reset',

    'the till has the Expense button; a cashier needs the PIN; the expense leaves his drawer and lands in Expenses' => function () use ($cashierOnTill): void {
        $client = $cashierOnTill();
        $till = $client->get('pos')->body;
        assert_contains('id="btn-expense"', $till);
        assert_contains('id="exp-pin"', $till, 'a cashier is asked for the PIN');
        $in = ['category' => 'Electricity', 'description' => 'generator', 'usd' => '10', 'lbp' => '450,000', 'pin' => ''];
        $r = $client->postJson('pos/expense', $in);
        assert_same(422, $r->status);
        assert_true(json_decode($r->body, true)['needs_pin'] === true);
        $r = $client->postJson('pos/expense', ['pin' => '1111'] + $in);
        assert_same(422, $r->status, 'a wrong PIN is refused');
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM expenses')->fetchColumn());
        $r = $client->postJson('pos/expense', ['pin' => '2468'] + $in);
        assert_same(200, $r->status, $r->body);
        assert_same('$10.00 + 450,000 LBP for Electricity', json_decode($r->body, true)['text']);
        $session = (int) Database::pdo()->query("SELECT id FROM cash_sessions WHERE status = 'open'")->fetchColumn();
        $x = Database::pdo()->query('SELECT category, description, usd_paid, lbp_paid, paid_from, session_id, user_id FROM expenses')->fetch(PDO::FETCH_ASSOC);
        $sara = (int) Database::pdo()->query("SELECT id FROM users WHERE username = 'sara'")->fetchColumn();
        assert_same(['category' => 'Electricity', 'description' => 'generator', 'usd_paid' => '10.00', 'lbp_paid' => '450000', 'paid_from' => 'drawer', 'session_id' => $session, 'user_id' => $sara], $x);
        assert_same(['USD' => '40.00', 'LBP' => '550000'], (new CashSession())->expected($session), 'both currencies left the drawer');
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'pin.override' AND entity = 'expense'")->fetchColumn());
        $admin = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $admin->get('expenses')->body;
        assert_contains('Electricity', $page);
        assert_contains('Drawer', $page);
    },

    'an empty amount or a missing category is refused before anything is written' => function () use ($cashierOnTill): void {
        $client = $cashierOnTill();
        $r = $client->postJson('pos/expense', ['category' => '', 'description' => '', 'usd' => '5', 'lbp' => '', 'pin' => '2468']);
        assert_same(422, $r->status);
        assert_contains('category', json_decode($r->body, true)['error']);
        $r = $client->postJson('pos/expense', ['category' => 'Water', 'description' => '', 'usd' => '', 'lbp' => '', 'pin' => '2468']);
        assert_same(422, $r->status);
        assert_contains('Enter an amount', json_decode($r->body, true)['error']);
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM expenses')->fetchColumn());
    },
];
