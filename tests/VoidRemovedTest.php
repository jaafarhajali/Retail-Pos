<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Register;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-10-05 (Aya): voids are removed; a sale is undone with a return. Sales voided before stay readable
 * (records are never edited or deleted): the list, the sale page and a reprint still say "voided".
 */
$sold = static function (): int {
    $p = make_product('Clay Bowl');
    $piece = (new ProductService())->addUnit($p, 'Piece', '1', false, true);
    (new ProductService())->setPrices($piece, '6', '');
    (new StockService())->adjust($p, $piece, '5', 'opening', '', '2', TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
    $r = (new SaleService())->complete([
        'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
        'lines' => [['product_id' => $p, 'unit_id' => $piece, 'qty' => '1']], 'invoice_discount' => '',
        'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '6']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
    ]);

    return (int) $r['id'];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'there is no way to void a sale any more: no route, no permission, no service, no box on the sale page' => function () use ($sold): void {
        $id = $sold();
        assert_false(method_exists(SaleService::class, 'void'));
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM permissions WHERE perm_key = 'sale.void'")->fetchColumn());
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('sales/view', ['id' => $id])->body;
        assert_not_contains('Void this sale', $page);
        assert_not_contains('sales/void', $page);
        assert_same(404, $client->post('sales/void', ['id' => (string) $id, 'reason' => 'x'])->status);
    },

    'a sale voided before the change still reads as voided everywhere' => function () use ($sold): void {
        $id = $sold();
        Database::pdo()->exec("UPDATE sales SET status = 'voided', void_reason = 'customer changed mind', voided_by = 1, voided_at = NOW() WHERE id = {$id}");
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('voided', $client->get('sales')->body);
        $page = $client->get('sales/view', ['id' => $id])->body;
        assert_contains('customer changed mind', $page);
        assert_contains('VOIDED', $client->get('sales/receipt', ['id' => $id, 'copy' => 1])->body);
    },
];
