<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Models\Register;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-11-05 (Aya): the shop's second way to be paid is Whish, not a card machine. Everywhere the screens and the
 * papers said "card" they now say "Whish": the till's payment list and its messages, the receipt, the sale page,
 * the X/Z report and the payments report. The stored method stays 'card' (financial records are append-only), so
 * every old sale still reads correctly.
 */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a sale paid by Whish says Whish on every screen and paper, never card' => function (): void {
        $svc = new ProductService();
        $p = make_product('Nakhla');
        $piece = $svc->addUnit($p, 'Piece', '1', false, true);
        $svc->setPrices($piece, '20', '');
        (new StockService())->adjust($p, $piece, '10', 'opening', '', '1', TEST_ADMIN_ID);
        $register = (new Register())->create('Register 01');
        $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
        $sale = (new SaleService())->complete([
            'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $p, 'unit_id' => $piece, 'qty' => '1']], 'invoice_discount' => '',
            'payments' => [['method' => 'card', 'currency' => 'USD', 'amount' => '20']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
        ]);

        $c = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('Paid Whish USD', $c->get('sales/receipt', ['id' => $sale['id']])->body);
        assert_contains('Whish USD', $c->get('sales/view', ['id' => $sale['id']])->body);
        $z = $c->get('sessions/print', ['id' => $session])->body;
        assert_contains('Whish USD', $z);
        assert_not_contains('card USD', $z);
        assert_contains('>Whish<', $c->get('reports', ['type' => 'payments'])->body);
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/pos.js');
        assert_contains('<option value="card">Whish</option>', $js, 'the till builds its payment list in the script');
        assert_contains('by Whish', $js);
        assert_not_contains('>Card<', $js);
    },
];
