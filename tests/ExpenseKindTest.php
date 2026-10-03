<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Supplier;
use App\Services\ExpenseService;
use App\Services\PartyService;

/**
 * 2026-10-04 (Aya): the expense form starts with "What is it? Expense / Supplier payment". A supplier payment needs a
 * supplier and no category (it is saved as "Supplier payment"); an expense needs a category and never touches a supplier.
 */
$supplier = static function (): int {
    $id = (new PartyService())->saveSupplier(0, 'Al Fakher Lebanon SAL', '01 234 567', '', true);
    (new Supplier())->addLedger($id, 'purchase', '80', null, null, TEST_ADMIN_ID, 'PUR-000001');

    return $id;
};
$in = static fn (array $extra): array => $extra + ['description' => '', 'usd' => '80', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'outside'];
$owed = static fn (int $id): string => number_format((float) Database::pdo()->query("SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_ledger WHERE supplier_id = {$id}")->fetchColumn(), 2, '.', '');

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a supplier payment needs no category and is saved as "Supplier payment"' => function () use ($supplier, $in, $owed): void {
        $id = $supplier();
        (new ExpenseService())->create($in(['kind' => 'supplier', 'category' => '', 'supplier_id' => $id]), TEST_ADMIN_ID);
        assert_same('Supplier payment', Database::pdo()->query('SELECT category FROM expenses')->fetchColumn());
        assert_same('0.00', $owed($id));
    },

    'a supplier payment without a supplier is refused' => function () use ($in): void {
        $e = assert_throws(DomainException::class, fn () => (new ExpenseService())->create($in(['kind' => 'supplier', 'category' => '', 'supplier_id' => 0]), TEST_ADMIN_ID));
        assert_contains('Choose the supplier', $e->getMessage());
    },

    'an expense still needs a category and never reduces a supplier debt' => function () use ($supplier, $in, $owed): void {
        $id = $supplier();
        $e = assert_throws(DomainException::class, fn () => (new ExpenseService())->create($in(['kind' => 'expense', 'category' => '', 'supplier_id' => 0]), TEST_ADMIN_ID));
        assert_contains('category', $e->getMessage());
        // A supplier left selected in a hidden field does not turn an expense into a payment.
        (new ExpenseService())->create($in(['kind' => 'expense', 'category' => 'Rent', 'supplier_id' => $id]), TEST_ADMIN_ID);
        assert_same(null, Database::pdo()->query('SELECT supplier_id FROM expenses')->fetchColumn());
        assert_same('80.00', $owed($id));
    },

    '"Pay supplier" opens the form on that supplier, with what we owe ready' => function () use ($supplier): void {
        $id = $supplier();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('expenses', ['supplier' => $id])->body;
        assert_contains('value="supplier" checked', $page);
        assert_contains('value="' . $id . '" data-owed="80.00" selected', $page);
        assert_contains('r=expenses&supplier=' . $id . '"', $client->get('suppliers/edit', ['id' => $id])->body);
    },

    'the page records a supplier payment without a category and lists it as one' => function () use ($supplier, $owed): void {
        $id = $supplier();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('expenses');
        $r = $client->post('expenses/store', ['kind' => 'supplier', 'category' => '', 'description' => 'part of PUR-000001', 'usd' => '30', 'lbp' => '',
            'expense_date' => date('Y-m-d'), 'paid_from' => 'outside', 'supplier_id' => (string) $id]);
        assert_same(302, $r->status);
        assert_same('50.00', $owed($id));
        $page = $client->get('expenses')->body;
        assert_contains('Supplier payment', $page);
        assert_contains('Al Fakher Lebanon SAL', $page);
    },
];
