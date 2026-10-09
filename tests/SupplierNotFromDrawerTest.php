<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Register;
use App\Models\Supplier;
use App\Services\CashService;
use App\Services\ExpenseService;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\PurchaseService;

/** Owner, 2026-10-09: a supplier is never paid from the drawer. "Paid now" on a purchase and a supplier payment come from outside. */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a purchase paid now leaves the open drawer untouched, even when "drawer" is sent' => function (): void {
        $session = (new CashService())->open((new Register())->create('Register 01'), TEST_ADMIN_ID, '100', '0');
        $supplier = (new PartyService())->saveSupplier(0, 'Al Fakher Lebanon', '', '', true);
        $p = make_product('Tobacco', 'g');
        $pack = (new ProductService())->addUnit($p, 'Pack', '250', false, false);
        $id = (new PurchaseService())->create($supplier, 'INV-1', date('Y-m-d'), [['product_id' => $p, 'unit_id' => $pack, 'qty' => '10', 'unit_cost' => '6']], '60', 'drawer', '', TEST_ADMIN_ID);
        assert_same(null, Database::pdo()->query("SELECT session_id FROM purchases WHERE id = {$id}")->fetchColumn());
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM cash_movements WHERE type IN ('supplier_payment', 'expense')")->fetchColumn());
        assert_same(['USD' => '100.00', 'LBP' => '0'], (new CashSession())->expected($session));
        assert_same('0.00', number_format((float) Database::pdo()->query("SELECT SUM(amount_usd) FROM supplier_ledger WHERE supplier_id = {$supplier}")->fetchColumn(), 2, '.', ''), 'the ledger still shows the payment');
    },

    'a supplier payment on the Expenses page is stored as paid from outside, with no cash movement' => function (): void {
        $session = (new CashService())->open((new Register())->create('Register 01'), TEST_ADMIN_ID, '100', '0');
        $supplier = (new PartyService())->saveSupplier(0, 'Beirut Charcoal', '', '', true);
        (new Supplier())->addLedger($supplier, 'purchase', '80', null, null, TEST_ADMIN_ID, 'PUR-000001');
        (new ExpenseService())->create(['kind' => 'supplier', 'supplier_id' => $supplier, 'usd' => '30', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'drawer'], TEST_ADMIN_ID);
        assert_same(['outside', null], array_values(Database::pdo()->query('SELECT paid_from, session_id FROM expenses')->fetch(PDO::FETCH_ASSOC)));
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM cash_movements WHERE type IN ('supplier_payment', 'expense')")->fetchColumn());
        assert_same(['USD' => '100.00', 'LBP' => '0'], (new CashSession())->expected($session));
        // an ordinary expense still comes out of the drawer
        (new ExpenseService())->create(['kind' => 'expense', 'category' => 'Rent', 'usd' => '10', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'drawer'], TEST_ADMIN_ID);
        assert_same(['USD' => '90.00', 'LBP' => '0'], (new CashSession())->expected($session));
    },

    'the purchase form has no "Paid from"; the supplier payment form says outside only' => function (): void {
        $supplier = (new PartyService())->saveSupplier(0, 'Beirut Charcoal', '', '', true);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('purchases/create')->body;
        assert_not_contains('name="paid_from"', $page);
        assert_contains('never paid from the till', $page);
        $page = $client->get('expenses', ['supplier' => $supplier])->body;
        assert_contains('never from the drawer', $page);
        assert_contains('data-kind="expense" hidden><label class="form-label">Paid from</label>', $page, 'the drawer choice is hidden for a supplier payment');
    },
];
