<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Register;
use App\Services\CashService;

/**
 * 2026-11-05 (Aya): a debt can be paid back in cash or by Whish. A Whish transfer is USD only, goes to the shop's
 * Whish account and never into the drawer (no cash movement, the drawer count stays right), and gives no change:
 * at most what he owes. The customer's statement says it was paid by Whish.
 */
$setup = static function (string $owes): array {
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '1000000');
    $customer = (new Customer())->create(['name' => 'Lounge 961', 'phone' => '76 555 111', 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);
    (new Customer())->addLedger(['customer_id' => $customer, 'type' => 'sale_credit', 'amount_usd' => $owes, 'note' => 'INV-000016']);

    return ['register' => $register, 'session' => $session, 'customer' => $customer];
};
$whish = static fn (array $s, string $usd, string $lbp = ''): array =>
    (new CashService())->collectDebt($s['customer'], $usd, $lbp, $s['session'], TEST_ADMIN_ID, 'LBP', false, 'whish');
$drawer = static fn (array $s): array => (new CashSession())->expected($s['session']);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'paid by Whish: the debt falls, the drawer does not move, the statement says Whish' => function () use ($setup, $whish, $drawer): void {
        $s = $setup('8.00');
        $r = $whish($s, '5');
        assert_same(['paid_usd' => '5.00', 'change_usd' => '0.00', 'change_lbp' => 0, 'balance' => '3.00'], $r);
        assert_same(['USD' => '100.00', 'LBP' => '1000000'], $drawer($s), 'nothing entered the drawer');
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM cash_movements WHERE type = \'debt_collection\'')->fetchColumn());
        $row = Database::pdo()->query("SELECT type, amount_usd, note FROM customer_ledger WHERE customer_id = {$s['customer']} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        assert_same(['type' => 'payment', 'amount_usd' => '-5.00', 'note' => 'Paid by Whish at the till'], $row);
    },

    'by Whish there is no change and no LBP: more than he owes, or an LBP amount, is refused' => function () use ($setup, $whish): void {
        $s = $setup('8.00');
        $e = assert_throws(DomainException::class, fn () => $whish($s, '10'));
        assert_contains('no change', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $whish($s, '', '450,000'));
        assert_contains('USD only', $e->getMessage());
        assert_same('8.00', (new Customer())->balance($s['customer']), 'nothing was recorded');
        // exactly what he owes is fine
        $r = $whish($s, '8');
        assert_same('0.00', $r['balance']);
    },

    'the till: the Customer window offers Cash / Whish, and the endpoint takes the method' => function () use ($setup): void {
        $s = $setup('8.00');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $till = $client->get('pos')->body;
        assert_contains('id="debt-method"', $till);
        assert_contains('>Whish</button>', $till);
        $r = $client->postJson('pos/debt', ['customer_id' => $s['customer'], 'usd' => '8', 'lbp' => '', 'method' => 'whish']);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same(['8.00', '0.00'], [$j['usd'], $j['balance']]);
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM cash_movements WHERE type = \'debt_collection\'')->fetchColumn());
    },
];
