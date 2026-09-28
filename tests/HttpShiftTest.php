<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Models\Register;
use App\Models\Sale;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/** One register, one open session: what each person sees while a register is busy, and what a device may do mid-shift. */
$adminOnRegister = static function (int $registerId): HttpClient {
    $client = login_as('admin', TEST_ADMIN_PASSWORD);
    $client->get('registers');
    $client->post('registers/bind', ['id' => $registerId]);
    $client->get('sessions/open');
    assert_same(302, $client->post('sessions/open', ['opening_usd' => '100', 'opening_lbp' => '0'])->status);

    return $client;
};

return [
    '__before' => 'test_db_reset',

    'a busy register: the cashier is told to ask the administrator and gets no button to a forbidden page' => function () use ($adminOnRegister): void {
        $client = $adminOnRegister((new Register())->create('Register 01'));
        assert_contains('It is yours', $client->get('sessions/open')->body);

        $till = $client->get('pos')->body;
        assert_contains('id="pin-input" type="text"', $till, 'the PIN is not a password field, so the browser never offers to save it');
        assert_contains('autocomplete="one-time-code"', $till);
        assert_not_contains('type="password"', $till);

        // The cashier signs in on the same device.
        make_user('sara');
        $client->get('dashboard');
        $client->post('auth/logout', []);
        $client->get('auth/login');
        assert_same(302, $client->post('auth/login', ['username' => 'sara', 'password' => 'password123'])->status);

        $open = $client->get('sessions/open')->body;
        assert_contains('already has an open session', $open);
        assert_contains('Ask the administrator to count and close', $open);
        assert_not_contains('sessions/view', $open);
        assert_not_contains('sessions%2Fview', $open);

        $dash = $client->get('dashboard')->body;
        assert_contains('In use by', $dash);
        assert_contains('Ask the administrator to count and close S-000001', $dash);
        assert_not_contains('No open session', $dash);
        assert_not_contains('Open a cash session', $dash);
        assert_not_contains('sessions/view', $dash);
        assert_not_contains('sessions%2Fview', $dash);
    },

    'a device in the middle of a shift cannot move to another register; a deactivated register comes back' => function () use ($adminOnRegister): void {
        $registers = new Register();
        $one = $registers->create('Register 01');
        $two = $registers->create('Register 02');
        $client = $adminOnRegister($one);

        $page = $client->get('registers')->body;
        assert_contains('is open on this device', $page);
        assert_same(302, $client->post('registers/bind', ['id' => $two])->status);
        assert_contains('is still open on it', $client->get('registers')->body);
        assert_same(null, $registers->find($two)['device_token_hash'], 'Register 02 did not take the device');
        assert_true($registers->find($one)['device_token_hash'] !== null, 'the device is still Register 01');

        assert_same(302, $client->post('registers/deactivate', ['id' => $one])->status);
        assert_same(1, (int) $registers->find($one)['is_active'], 'a register with an open session is not deactivated');

        $client->get('registers');
        assert_same(302, $client->post('registers/deactivate', ['id' => $two])->status);
        assert_same(0, (int) $registers->find($two)['is_active']);
        assert_contains('Reactivate', $client->get('registers')->body);
        assert_same(302, $client->post('registers/activate', ['id' => $two])->status);
        assert_same(1, (int) $registers->find($two)['is_active']);
        $client->get('registers');
        assert_same(302, $client->post('registers/activate', ['id' => $two])->status);
        assert_contains('already active', $client->get('registers')->body);
    },

    'the sales list marks returned invoices and the returns report says Walk-in' => function (): void {
        Auth::login(TEST_ADMIN_ID);
        $products = new ProductService();
        $p = make_product('Charcoal', 'g');
        $kg = $products->addUnit($p, 'kg', '1000', true, false);
        $products->setPrices($kg, '15', '');
        (new StockService())->adjust($p, $kg, '10', 'opening', '', '10', TEST_ADMIN_ID);
        $registerId = (new Register())->create('Register 01');
        $sessionId = (new CashService())->open($registerId, TEST_ADMIN_ID, '100', '0');
        $sale = (new SaleService())->complete([
            'register_id' => $registerId, 'session_id' => $sessionId, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $p, 'unit_id' => $kg, 'qty' => '2']], 'invoice_discount' => '',
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '30']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
        ]);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $plain = $client->get('sales')->body;
        assert_not_contains('returned</span>', $plain);

        $item = (int) (new Sale())->items($sale['id'])[0]['id'];
        $return = static fn () => (new ReturnService())->create($sale['id'], [['sale_item_id' => $item, 'qty' => '1', 'condition' => 'restock']], 'USD', 'changed mind', $sessionId, $registerId, TEST_ADMIN_ID);
        $return();
        $list = $client->get('sales')->body;
        assert_contains('>part returned</span>', $list);
        assert_contains('$15.00 of $30.00 refunded', $list);
        assert_contains('<td dir="auto">Walk-in</td>', $client->get('reports', ['type' => 'returns'])->body);

        $return();
        $list = $client->get('sales')->body;
        assert_contains('>returned</span>', $list);
        assert_not_contains('part returned', $list);
    },
];
