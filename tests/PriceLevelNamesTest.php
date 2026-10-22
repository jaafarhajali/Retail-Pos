<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Models\Customer;
use App\Models\Register;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-10-22 (Aya): wherever a price level is shown, the Arabic word the shop uses stands beside the English one:
 * "Retail / مفرق" and "Wholesale / جملة". The one exception to "UI text in English only".
 */
return [
    '__before' => 'test_db_reset',

    'the two price levels have one name each, English and Arabic' => function (): void {
        assert_same('Retail / مفرق', level_name('retail'));
        assert_same('Wholesale / جملة', level_name('wholesale'));
    },

    'customers, sales, the receipt, the Z report and the product page show them' => function (): void {
        \App\Core\Auth::login(TEST_ADMIN_ID);
        (new Customer())->create(['name' => 'Lounge 961', 'phone' => null, 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);
        $p = make_product('Nakhla');
        $piece = (new ProductService())->addUnit($p, 'Piece', '1', false, true);
        (new ProductService())->setPrices($piece, '3', '2.50');
        (new StockService())->adjust($p, $piece, '10', 'opening', '', '1', TEST_ADMIN_ID);
        $register = (new Register())->create('Register 01');
        $session = (new CashService())->open($register, TEST_ADMIN_ID, '0', '0');
        $sale = (new SaleService())->complete([
            'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'wholesale',
            'lines' => [['product_id' => $p, 'unit_id' => $piece, 'qty' => '1']], 'invoice_discount' => '',
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '2.50']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
        ]);

        $c = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('Wholesale / جملة', $c->get('customers')->body, 'the customer list badge');
        $edit = $c->get('customers/edit')->body;
        assert_contains('>Retail / مفرق</option>', $edit, 'the price level list on the customer form');
        assert_contains('>Wholesale / جملة</option>', $edit);
        assert_contains('Wholesale / جملة', $c->get('sales')->body, 'the sales list badge');
        assert_contains('Wholesale / جملة', $c->get('sales/view', ['id' => $sale['id']])->body, 'the sale page');
        assert_contains('Wholesale / جملة', $c->get('sales/receipt', ['id' => $sale['id']])->body, 'the printed receipt');
        $z = $c->get('sessions/print', ['id' => $session])->body;
        assert_contains('Retail / مفرق', $z, 'the X/Z report lines');
        assert_contains('Wholesale / جملة', $z);
        $form = $c->get('products/create')->body;
        assert_contains('<th>Retail / مفرق</th>', $form, 'the price columns of the product page');
        assert_contains('<th>Wholesale / جملة</th>', $form);
        $report = $c->get('reports', ['type' => 'sales', 'by' => 'level'])->body;
        assert_contains('Wholesale / جملة', $report, 'the sales report by level');
        assert_contains('>Price level (مفرق / جملة)</option>', $report, 'the Group by choice names the grouping, not both levels');
        assert_contains('<th>Price level</th>', $report);
    },

    'the till gets both names for its Retail / Wholesale button and the customer list' => function (): void {
        $c = login_as('admin', TEST_ADMIN_PASSWORD);
        $c->get('registers');
        $c->post('registers/bind', ['id' => (new Register())->create('Register 01')]);
        $c->get('sessions/open');
        $c->post('sessions/open', ['opening_usd' => '0', 'opening_lbp' => '0']);
        $till = $c->get('pos')->body;
        assert_contains('id="btn-level" title="Price level">Retail / مفرق</button>', $till);
        assert_same(1, preg_match('~levels: (\{[^}]*\})~', $till, $m), 'the names reach the till script');
        assert_same(['retail' => 'Retail / مفرق', 'wholesale' => 'Wholesale / جملة'], json_decode($m[1], true));
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/pos.js');
        assert_contains('P.levels[level]', $js);
        assert_contains('P.levels.wholesale', $js);
    },
];
