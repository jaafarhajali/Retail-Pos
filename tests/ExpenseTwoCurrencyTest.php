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

/** 2026-10-04 (Aya): one expense or supplier payment can be paid partly in USD and partly in LBP. */
$base = static fn (array $extra): array => $extra + ['category' => 'Electricity', 'description' => 'Generator', 'expense_date' => date('Y-m-d'),
    'paid_from' => 'outside', 'supplier_id' => 0];
$row = static fn (): array => Database::pdo()->query('SELECT usd_paid, lbp_paid, amount_usd, exchange_rate FROM expenses ORDER BY id DESC LIMIT 1')->fetch();

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'an expense paid $10 + 450,000 LBP from the drawer takes both currencies out of it' => function () use ($base, $row): void {
        $session = (new CashService())->open((new Register())->create('Register 01'), TEST_ADMIN_ID, '100', '1000000');
        (new ExpenseService())->create($base(['usd' => '10', 'lbp' => '450,000', 'paid_from' => 'drawer']), TEST_ADMIN_ID);
        assert_same(['usd_paid' => '10.00', 'lbp_paid' => '450000', 'amount_usd' => '15.00', 'exchange_rate' => 90000], $row());
        assert_same(['USD' => '90.00', 'LBP' => '550000'], (new CashSession())->expected($session));
    },

    'one currency alone still works, in either field' => function () use ($base, $row): void {
        (new ExpenseService())->create($base(['usd' => '20', 'lbp' => '']), TEST_ADMIN_ID);
        assert_same(['usd_paid' => '20.00', 'lbp_paid' => '0', 'amount_usd' => '20.00', 'exchange_rate' => 90000], $row());
        (new ExpenseService())->create($base(['usd' => '', 'lbp' => '900000']), TEST_ADMIN_ID);
        assert_same(['usd_paid' => '0.00', 'lbp_paid' => '900000', 'amount_usd' => '10.00', 'exchange_rate' => 90000], $row());
    },

    'nothing in either field is refused' => function () use ($base): void {
        $e = assert_throws(DomainException::class, fn () => (new ExpenseService())->create($base(['usd' => '', 'lbp' => '']), TEST_ADMIN_ID));
        assert_contains('Enter an amount', $e->getMessage());
    },

    'a supplier paid $50 + 900,000 LBP owes $60 less, in one ledger line' => function () use ($base): void {
        $supplier = (new PartyService())->saveSupplier(0, 'Al Fakher Lebanon SAL', '01 234 567', '', true);
        (new Supplier())->addLedger($supplier, 'purchase', '100', null, null, TEST_ADMIN_ID, 'PUR-000001');
        (new ExpenseService())->create($base(['category' => 'Supplier payment', 'usd' => '50', 'lbp' => '900,000', 'supplier_id' => $supplier]), TEST_ADMIN_ID);
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM supplier_ledger WHERE type = 'payment'")->fetchColumn());
        assert_same('40.00', number_format((float) Database::pdo()->query("SELECT SUM(amount_usd) FROM supplier_ledger WHERE supplier_id = {$supplier}")->fetchColumn(), 2, '.', ''));
    },

    'the expenses page takes both amounts and lists them together' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('expenses');
        $r = $client->post('expenses/store', ['category' => 'Rent', 'description' => '', 'usd' => '200', 'lbp' => '4,500,000',
            'expense_date' => date('Y-m-d'), 'paid_from' => 'outside', 'supplier_id' => '0']);
        assert_same(302, $r->status);
        $page = $client->get('expenses')->body;
        assert_contains('$200.00 + 4,500,000 LBP', $page);
        assert_contains('$250.00', $page);
    },
];
