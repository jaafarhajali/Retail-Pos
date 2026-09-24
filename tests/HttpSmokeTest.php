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
];
