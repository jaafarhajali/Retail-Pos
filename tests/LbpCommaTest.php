<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\ExchangeRate;
use App\Models\Register;
use App\Services\CashService;
use App\Services\ExchangeRateService;
use App\Services\ExpenseService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/** LBP fields show 10,000,000 while it is typed, so every place that reads LBP accepts the commas. */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'LBP typed with thousands separators is read as the same number' => function (): void {
        assert_same(10000000, CashService::parseLbp('10,000,000', false));
        assert_same(10000000, CashService::parseLbp('10000000', false));
        assert_same(5000, CashService::parseLbp(' 5,000 ', false));
        assert_same(0, CashService::parseLbp('', true));
        assert_throws(DomainException::class, fn () => CashService::parseLbp('10,000,00a', false));
    },

    'rate, float, cash in, expense and a sale payment all accept 1,000,000' => function (): void {
        (new ExchangeRateService())->set('89,500');
        assert_same(89500, (new ExchangeRate())->current());

        $registerId = (new Register())->create('Register 01');
        $cash = new CashService();
        $sessionId = $cash->open($registerId, TEST_ADMIN_ID, '100', '1,000,000');
        assert_same('1000000', (string) (new CashSession())->expected($sessionId)['LBP']);

        $cash->cashInOut($sessionId, 'in', 'LBP', '500,000', 'change from the bank', TEST_ADMIN_ID);
        assert_same('1500000', (string) (new CashSession())->expected($sessionId)['LBP']);

        (new ExpenseService())->create(['category' => 'Electricity', 'description' => '', 'currency' => 'LBP', 'amount' => '450,000',
            'expense_date' => date('Y-m-d'), 'paid_from' => 'drawer', 'supplier_id' => 0], TEST_ADMIN_ID);
        assert_same('1050000', (string) (new CashSession())->expected($sessionId)['LBP']);
        assert_same('5.03', Database::pdo()->query('SELECT amount_usd FROM expenses')->fetchColumn());   // 450,000 / 89,500

        $products = new ProductService();
        $p = make_product('Charcoal', 'g');
        $kg = $products->addUnit($p, 'kg', '1000', true, false);
        $products->setPrices($kg, '15', '');
        (new StockService())->adjust($p, $kg, '10', 'opening', '', '10', TEST_ADMIN_ID);
        $sale = (new SaleService())->complete([
            'register_id' => $registerId, 'session_id' => $sessionId, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $p, 'unit_id' => $kg, 'qty' => '2']], 'invoice_discount' => '',
            'payments' => [['method' => 'cash', 'currency' => 'LBP', 'amount' => '2,685,000']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
        ]);
        assert_same('30.00', $sale['total_usd']);
        assert_same(0, (int) $sale['change_lbp']);
        assert_same('2685000', (string) (int) Database::pdo()->query("SELECT amount FROM sale_payments WHERE currency = 'LBP'")->fetchColumn());
    },

    'the LBP fields are marked for grouping and the forms accept what the browser then sends' => function (): void {
        $registerId = (new Register())->create('Register 01');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('data-lbp="always"', $client->get('rates')->body);
        assert_contains('data-lbp-when="#expense-currency"', $client->get('expenses')->body);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $registerId]);
        assert_contains('name="opening_lbp" inputmode="numeric" data-lbp="always"', $client->get('sessions/open')->body);
        assert_same(302, $client->post('sessions/open', ['opening_usd' => '100', 'opening_lbp' => '2,500,000'])->status);
        $sessionId = (int) Database::pdo()->query('SELECT id FROM cash_sessions')->fetchColumn();
        assert_same('2500000', (string) (new CashSession())->expected($sessionId)['LBP']);
        assert_contains('data-lbp-when="#cash-currency"', $client->get('sessions/view', ['id' => $sessionId])->body);
    },
];
