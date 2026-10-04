<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Customer;
use App\Models\Register;
use App\Models\User;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-10-04 (Aya): Returns opens in the till as a pop-up. A cashier needs the administrator's PIN to open it (the
 * Cashier role no longer holds return.create, migration 007); the invoice is found by customer, item or number.
 */
$cashierOnTill = static function (): array {
    (new User())->setPin(TEST_ADMIN_ID, '1234');
    $p = make_product('Fumari White Gummi Bear');
    $pack = (new ProductService())->addUnit($p, 'Pack', '1', false, true);
    (new ProductService())->setPrices($pack, '8', '');
    (new StockService())->adjust($p, $pack, '10', 'opening', '', '5', TEST_ADMIN_ID);
    $customer = (new Customer())->create(['name' => 'Lounge 961', 'phone' => '76 555 111', 'notes' => null, 'default_price_level' => 'retail', 'credit_limit_usd' => null]);
    $register = (new Register())->create('Register 01');
    make_user('sara');

    $client = login_as('admin', TEST_ADMIN_PASSWORD);
    $client->get('registers');
    $client->post('registers/bind', ['id' => $register]);
    $client->post('auth/logout', []);
    $client->get('auth/login');
    $client->post('auth/login', ['username' => 'sara', 'password' => 'password123']);
    $client->get('sessions/open');
    assert_same(302, $client->post('sessions/open', ['opening_usd' => '100', 'opening_lbp' => '1000000'])->status);
    $session = (int) Database::pdo()->query('SELECT id FROM cash_sessions')->fetchColumn();

    Auth::login(TEST_ADMIN_ID);
    $sale = (new SaleService())->complete([
        'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'retail',
        'lines' => [['product_id' => $p, 'unit_id' => $pack, 'qty' => '1']], 'invoice_discount' => '',
        'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '8']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
    ]);

    return ['client' => $client, 'sale' => (int) $sale['id'], 'register' => $register];
};
$json = static fn ($r): array => json_decode($r->body, true) ?? [];

return [
    '__before' => 'test_db_reset',

    'the Cashier role no longer processes returns alone' => function (): void {
        $keys = Database::pdo()->query("SELECT perm_key FROM role_permissions WHERE role_id = 2")->fetchAll(PDO::FETCH_COLUMN);
        assert_false(in_array('return.create', $keys, true));
    },

    'a cashier opens the return pop-up only with the administrator PIN' => function () use ($cashierOnTill, $json): void {
        $c = $cashierOnTill()['client'];
        $none = $c->postJson('pos/return-pin', ['pin' => '']);
        assert_same(422, $none->status);
        assert_true($json($none)['needs_pin'] === true);
        assert_contains('Wrong PIN', $json($c->postJson('pos/return-pin', ['pin' => '9999']))['error']);
        assert_same(200, $c->postJson('pos/return-pin', ['pin' => '1234'])->status);
    },

    'the till finds the invoice by customer, by item or by its number' => function () use ($cashierOnTill, $json): void {
        $c = $cashierOnTill()['client'];
        assert_same(403, $c->get('pos/return-search', ['q' => 'Lounge'])->status, 'no searching before the PIN');
        $c->postJson('pos/return-pin', ['pin' => '1234']);
        foreach (['Lounge', 'gummi', '1', 'INV-000001'] as $q) {
            $found = $json($c->get('pos/return-search', ['q' => $q]))['sales'];
            assert_same('INV-000001', $found[0]['invoice_no'] ?? null, "search {$q}");
        }
        assert_same([], $json($c->get('pos/return-search', ['q' => 'nobody']))['sales']);
    },

    'the pop-up gets the lines that can still come back' => function () use ($cashierOnTill, $json): void {
        $t = $cashierOnTill();
        $t['client']->postJson('pos/return-pin', ['pin' => '1234']);
        $j = $json($t['client']->get('pos/return-sale', ['id' => $t['sale']]));
        assert_same('Lounge 961', $j['sale']['customer']);
        assert_same('Fumari White Gummi Bear', $j['lines'][0]['name']);
        assert_same('1', $j['lines'][0]['left']);
        assert_same('8.00', $j['lines'][0]['paid']);
    },

    'without the PIN the return is refused; with it, it is recorded and the approval is audited' => function () use ($cashierOnTill, $json): void {
        $t = $cashierOnTill();
        $itemId = (int) Database::pdo()->query('SELECT id FROM sale_items')->fetchColumn();
        $body = ['sale_id' => $t['sale'], 'items' => [['sale_item_id' => $itemId, 'qty' => '1', 'condition' => 'restock']], 'usd' => '8', 'lbp' => '', 'reason' => 'wrong flavour'];
        $refused = $t['client']->postJson('pos/return', $body);
        assert_same(422, $refused->status);
        assert_true($json($refused)['needs_pin'] === true);
        $ok = $t['client']->postJson('pos/return', $body + ['pin' => '1234']);
        assert_same(200, $ok->status, $ok->body);
        assert_same('RTN-000001', $json($ok)['return_no']);
        assert_contains('returns/receipt', $json($ok)['receipt']);
        assert_same(200, $t['client']->get('returns/receipt', ['id' => $json($ok)['id']])->status, 'the cashier prints it');
        $audit = Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'pin.override' AND entity = 'return'")->fetchColumn();
        assert_contains('"approved_by":"admin"', (string) $audit);
    },

    'an administrator returns from the till without a PIN' => function () use ($json): void {
        $p = make_product('Clay Bowl');
        $piece = (new ProductService())->addUnit($p, 'Piece', '1', false, true);
        (new ProductService())->setPrices($piece, '6', '');
        (new StockService())->adjust($p, $piece, '5', 'opening', '', '2', TEST_ADMIN_ID);
        $register = (new Register())->create('Register 01');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $register]);
        $client->get('sessions/open');
        $client->post('sessions/open', ['opening_usd' => '100', 'opening_lbp' => '0']);
        $sale = $json($client->postJson('pos/complete', ['price_level' => 'retail', 'lines' => [['product_id' => $p, 'unit_id' => $piece, 'qty' => '1']],
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '6']], 'change_currency' => 'LBP']));
        assert_same(200, $client->postJson('pos/return-pin', ['pin' => ''])->status, 'no PIN asked of an administrator');
        $itemId = (int) Database::pdo()->query('SELECT id FROM sale_items')->fetchColumn();
        $ok = $client->postJson('pos/return', ['sale_id' => $sale['id'], 'items' => [['sale_item_id' => $itemId, 'qty' => '1', 'condition' => 'waste']], 'usd' => '6', 'lbp' => '']);
        assert_same(200, $ok->status, $ok->body);
    },

    'the till opens Returns as a pop-up, not a link to another page' => function () use ($cashierOnTill): void {
        $page = $cashierOnTill()['client']->get('pos')->body;
        assert_contains('id="btn-returns"', $page);
        assert_contains('id="m-return"', $page);
    },
];
