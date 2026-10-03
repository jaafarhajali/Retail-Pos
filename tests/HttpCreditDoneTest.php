<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Models\Customer;
use App\Models\Register;
use App\Services\ProductService;
use App\Services\StockService;

/** U10: a sale on credit must not look paid. The till gets the split and the new balance; the receipt says ON CREDIT. */
$open = static function (): array {
    $p = make_product('Clay Bowl');
    $piece = (new ProductService())->addUnit($p, 'Piece', '1', false, false);
    (new ProductService())->setPrices($piece, '15', '');
    (new StockService())->adjust($p, $piece, '10', 'opening', '', '5', TEST_ADMIN_ID);
    $customer = (new Customer())->create(['name' => 'Ahmad Saleh', 'phone' => '03 111 222', 'notes' => null, 'default_price_level' => 'retail', 'credit_limit_usd' => '50']);
    $registerId = (new Register())->create('Register 01');
    $client = login_as('admin', TEST_ADMIN_PASSWORD);
    $client->get('registers');
    $client->post('registers/bind', ['id' => $registerId]);
    $client->get('sessions/open');
    $client->post('sessions/open', ['opening_usd' => '100', 'opening_lbp' => '0']);

    return [$client, $p, $piece, $customer];
};

$sell = static function ($client, int $p, int $piece, array $payments, int $customer = 0): array {
    $r = $client->postJson('pos/complete', ['price_level' => 'retail', 'customer_id' => $customer,
        'lines' => [['product_id' => $p, 'unit_id' => $piece, 'qty' => '1']], 'payments' => $payments, 'change_currency' => 'LBP']);
    assert_same(200, $r->status, $r->body);

    return json_decode($r->body, true);
};

return [
    '__before' => 'test_db_reset',

    'a credit sale tells the till how much went on credit and what the customer now owes' => function () use ($open, $sell): void {
        [$client, $p, $piece, $customer] = $open();
        $j = $sell($client, $p, $piece, [['method' => 'credit', 'currency' => 'USD', 'amount' => '15']], $customer);
        assert_same('15.00', $j['credit_usd']);
        assert_same('0.00', $j['cash_usd']);
        assert_same('0.00', $j['card_usd']);
        assert_same('Ahmad Saleh', $j['customer']['name']);
        assert_same('15.00', $j['customer']['balance']);
        assert_same('50.00', $j['customer']['limit']);
    },

    'a part-cash part-credit sale splits the amounts' => function () use ($open, $sell): void {
        [$client, $p, $piece, $customer] = $open();
        $j = $sell($client, $p, $piece, [['method' => 'cash', 'currency' => 'USD', 'amount' => '5'], ['method' => 'credit', 'currency' => 'USD', 'amount' => '10']], $customer);
        assert_same('5.00', $j['cash_usd']);
        assert_same('10.00', $j['credit_usd']);
        assert_same('10.00', $j['customer']['balance']);
    },

    'a cash sale has no customer block and nothing on credit' => function () use ($open, $sell): void {
        [$client, $p, $piece] = $open();
        $j = $sell($client, $p, $piece, [['method' => 'cash', 'currency' => 'USD', 'amount' => '15']]);
        assert_same('0.00', $j['credit_usd']);
        assert_same('15.00', $j['cash_usd']);
        assert_same(null, $j['customer']);
        assert_not_contains('ON CREDIT', $client->get('sales/receipt', ['id' => $j['id']])->body);
    },

    'the receipt of a credit sale says ON CREDIT with the balance at that sale and a signature line' => function () use ($open, $sell): void {
        [$client, $p, $piece, $customer] = $open();
        $first = $sell($client, $p, $piece, [['method' => 'credit', 'currency' => 'USD', 'amount' => '15']], $customer);
        $second = $sell($client, $p, $piece, [['method' => 'credit', 'currency' => 'USD', 'amount' => '15']], $customer);
        $body = $client->get('sales/receipt', ['id' => $second['id']])->body;
        assert_contains('ON CREDIT', $body);
        assert_contains('Balance owed', $body);
        assert_contains('$30.00', $body);
        assert_contains('signature', $body);
        // A reprint of the first sale still shows what was owed right after it, not today's balance.
        $reprint = $client->get('sales/receipt', ['id' => $first['id'], 'copy' => 1])->body;
        assert_contains('$15.00', $reprint);
        assert_not_contains('$30.00', $reprint);
    },
];
