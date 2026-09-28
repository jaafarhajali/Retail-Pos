<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Models\Register;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\RegisterService;
use App\Services\StockService;

/** Every GET page opens for the admin; the till completes a sale through its JSON endpoint. */
return [
    '__before' => 'test_db_reset',

    'every admin page answers 200' => function (): void {
        $p = make_product('Charcoal', 'g');
        $kg = (new ProductService())->addUnit($p, 'kg', '1000', true, false);
        (new ProductService())->setPrices($kg, '15', '');
        (new StockService())->adjust($p, $kg, '5', 'opening', '', '10', TEST_ADMIN_ID);
        $registerId = (new Register())->create('Register 01');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        foreach (['dashboard', 'products', 'categories', 'stock', 'stock/adjust', 'purchases', 'purchases/create', 'suppliers', 'suppliers/edit', 'customers', 'customers/edit',
                  'sessions', 'sessions/open', 'sales', 'returns', 'expenses', 'counts', 'backup', 'reports', 'users', 'roles', 'registers', 'rates', 'settings', 'audit', 'pos'] as $route) {
            $r = $client->get($route);
            assert_same(200, $r->status, "GET {$route}");
        }
        foreach (['payments', 'profit', 'credit', 'stock', 'purchases', 'returns', 'waste', 'sessions'] as $type) {
            assert_same(200, $client->get('reports', ['type' => $type])->status, "report {$type}");
            assert_same(200, $client->get('reports', ['type' => $type, 'print' => 1])->status, "report {$type} print");
        }
    },

    'the till sells and prints once the device is linked and a session is open' => function (): void {
        $p = make_product('Charcoal', 'g');
        $kg = (new ProductService())->addUnit($p, 'kg', '1000', true, false);
        (new ProductService())->setPrices($kg, '15', '');
        (new ProductService())->addBarcode($kg, '6291041500213');
        $registerId = (new Register())->create('Register 01');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $registerId]);
        $client->get('sessions/open');
        assert_same(302, $client->post('sessions/open', ['opening_usd' => '100', 'opening_lbp' => '500000'])->status);
        $pos = $client->get('pos');
        assert_same(200, $pos->status);
        assert_contains('pos-body', $pos->body, 'the till renders, not the blocked page');
        $data = json_decode($client->get('pos/data')->body, true);
        assert_same($kg, $data['barcodes']['6291041500213']['u']);

        $r = $client->postJson('pos/complete', ['price_level' => 'retail', 'lines' => [['product_id' => $p, 'unit_id' => $kg, 'qty' => '2']],
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '50']], 'change_currency' => 'LBP']);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same('INV-000001', $j['invoice_no']);
        assert_same(1800000, $j['change_lbp']);
        assert_contains('Charcoal', $client->get('sales/receipt', ['id' => $j['id']])->body);
        assert_contains('COPY', $client->get('sales/receipt', ['id' => $j['id'], 'copy' => 1])->body);
        assert_same(200, $client->get('sales/view', ['id' => $j['id']])->status);
        $sessionId = (int) Database::pdo()->query('SELECT id FROM cash_sessions')->fetchColumn();
        assert_contains('X REPORT', $client->get('sessions/print', ['id' => $sessionId])->body);
        assert_same(200, $client->get('sessions/close', ['id' => $sessionId])->status);
        assert_same(200, $client->get('returns', ['invoice' => 'INV-000001'])->status);
    },
    'the shop logo uploads, shows on the sign-in page and receipts, and can be removed' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $img = imagecreatetruecolor(1200, 300);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 40, 90));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        $response = $client->postMultipart('settings/logo', [], ['logo' => ['logo.png', $bytes, 'image/png']]);
        assert_same(302, $response->status);
        $file = (string) Database::pdo()->query("SELECT setting_value FROM settings WHERE setting_key = 'shop_logo'")->fetchColumn();
        assert_true((bool) preg_match('/^logo-[a-f0-9]{8}\.png$/', $file), "logo file name: $file");
        assert_true(is_file(UPLOADS_PATH . '/' . $file));
        [$w, $h] = getimagesize(UPLOADS_PATH . '/' . $file);
        assert_same([600, 150], [$w, $h], 'resized to fit 600 wide');
        assert_contains('class="app-brand-logo"', (new HttpClient())->get('auth/login')->body);
        assert_contains('Remove logo', $client->get('settings')->body);

        $bad = $client->postMultipart('settings/logo', [], ['logo' => ['x.txt', 'not an image', 'text/plain']]);
        assert_same(302, $bad->status);
        assert_true(is_file(UPLOADS_PATH . '/' . $file), 'a refused upload keeps the old logo');

        assert_same(302, $client->post('settings/logo-delete', [])->status);
        clearstatcache();
        assert_false(is_file(UPLOADS_PATH . '/' . $file));
        assert_not_contains('class="app-brand-logo"', (new HttpClient())->get('auth/login')->body);
    },
    'the till checks the administrator\'s PIN for a cashier; the cashier has no PIN card' => function (): void {
        (new \App\Models\User())->setPin(TEST_ADMIN_ID, '2468');
        $cashierId = make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        $ok = $cashier->postJson('pos/pin', ['pin' => '2468', 'for' => 'Discount of $2.00']);
        assert_same(200, $ok->status);
        assert_contains('"by":"admin"', $ok->body);
        $bad = $cashier->postJson('pos/pin', ['pin' => '1111', 'for' => 'Discount of $2.00']);
        assert_same(422, $bad->status);
        assert_contains('Wrong PIN', $bad->body);
        $admin = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_not_contains('Approval PIN', $admin->get('users/edit', ['id' => $cashierId])->body);
        assert_contains('Approval PIN', $admin->get('users/edit', ['id' => TEST_ADMIN_ID])->body);
    },

    'the till learns that a line is below cost, never the cost' => function (): void {
        $p = make_product('Charcoal', 'g');
        $kg = (new ProductService())->addUnit($p, 'kg', '1000', true, false);
        (new ProductService())->setPrices($kg, '15', '');
        (new ProductService())->setCost($p, '11.37', 1000);
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        $line = ['product_id' => $p, 'unit_id' => $kg, 'qty' => '1', 'amount_usd' => '', 'price' => null];
        $low = $cashier->postJson('pos/check', ['price_level' => 'retail', 'invoice_discount' => '0.00', 'lines' => [$line + ['discount' => '4']]]);
        assert_same(200, $low->status);
        assert_same(['below_cost' => [['i' => 0, 'name' => 'Charcoal']]], json_decode($low->body, true));
        assert_not_contains('11.37', $low->body);
        $ok = $cashier->postJson('pos/check', ['price_level' => 'retail', 'invoice_discount' => '0.00', 'lines' => [$line + ['discount' => '3']]]);
        assert_same(['below_cost' => []], json_decode($ok->body, true));
        assert_same(['below_cost' => []], json_decode($cashier->postJson('pos/check', ['lines' => []])->body, true));
    },

    'an SVG logo is kept as vector and served as SVG; an SVG with a script is refused' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="600pt" height="614pt" viewBox="0 0 600 614"><path d="M0 0h600v614H0z"/></svg>';
        assert_same(302, $client->postMultipart('settings/logo', [], ['logo' => ['crest.svg', $svg, 'image/svg+xml']])->status);
        $file = (string) Database::pdo()->query("SELECT setting_value FROM settings WHERE setting_key = 'shop_logo'")->fetchColumn();
        assert_true((bool) preg_match('/^logo-[a-f0-9]{8}\.svg$/', $file), "svg file name: $file");
        assert_same($svg, file_get_contents(UPLOADS_PATH . '/' . $file), 'stored unchanged');
        $served = get_headers(dirname(TestServer::url()) . '/' . UPLOADS_URL . '/' . $file, true);
        assert_true(is_array($served) && str_contains((string) $served[0], '200'), 'the logo file is served');
        assert_contains('image/svg+xml', (string) ($served['Content-Type'] ?? ''));
        assert_contains($file, (new HttpClient())->get('auth/login')->body);
        assert_contains('class="print-logo"', $client->get('reports', ['type' => 'sales', 'print' => '1'])->body);

        $evil = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path d="M0 0h1v1z"/></svg>';
        assert_same(302, $client->postMultipart('settings/logo', [], ['logo' => ['evil.svg', $evil, 'image/svg+xml']])->status);
        assert_contains('scripts, links or embedded files', $client->get('settings')->body);
        assert_same($file, (string) Database::pdo()->query("SELECT setting_value FROM settings WHERE setting_key = 'shop_logo'")->fetchColumn(), 'the good logo stays');
        assert_same(302, $client->post('settings/logo-delete', [])->status);
    },
];