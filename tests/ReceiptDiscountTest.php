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
 * 2026-11-05 (Aya): a discount must be visible on the printed invoice, whichever way it was given. The receipt
 * prints each item at its full price, then "Subtotal", "Discount −$…" (line discounts and the invoice discount
 * together) and TOTAL, so the paper adds up: items = Subtotal, Subtotal − Discount = TOTAL.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $p = make_product('Nakhla');
    $piece = $svc->addUnit($p, 'Piece', '1', false, true);
    $svc->setPrices($piece, '2.50', '');
    (new StockService())->adjust($p, $piece, '50', 'opening', '', '1', TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');

    return ['p' => $p, 'piece' => $piece, 'register' => $register, 'session' => $session];
};
$sell = static fn (array $s, array $lines, string $invoiceDiscount, string $paid): array => (new SaleService())->complete([
    'register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
    'lines' => $lines, 'invoice_discount' => $invoiceDiscount,
    'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => $paid]], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
]);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a line discount prints as a Discount row: 10 × $2.50 with $0.50 off = Subtotal $25.00, Discount −$0.50, TOTAL $24.50' => function () use ($setup, $sell): void {
        $s = $setup();
        $sale = $sell($s, [['product_id' => $s['p'], 'unit_id' => $s['piece'], 'qty' => '10', 'discount' => '0.50']], '', '24.50');
        $r = login_as('admin', TEST_ADMIN_PASSWORD)->get('sales/receipt', ['id' => $sale['id']])->body;
        assert_contains('>Subtotal<', $r);
        assert_contains('$25.00', $r, 'the items at their full price');
        assert_contains('>Discount<', $r);
        assert_contains('-$0.50', $r);
        assert_contains('$24.50', $r);
    },

    'an invoice discount prints the same way, and the items add up to the Subtotal' => function () use ($setup, $sell): void {
        $s = $setup();
        $sale = $sell($s, [['product_id' => $s['p'], 'unit_id' => $s['piece'], 'qty' => '10']], '5', '20');
        $r = login_as('admin', TEST_ADMIN_PASSWORD)->get('sales/receipt', ['id' => $sale['id']])->body;
        assert_contains('>Subtotal<', $r);
        assert_contains('>Discount<', $r);
        assert_contains('-$5.00', $r);
        assert_contains('$20.00', $r);
        assert_same(1, preg_match_all('/\$25\.00/', $r, $m) >= 1 ? 1 : 0, 'the item line and the Subtotal both say $25.00');
    },

    'no discount: no Subtotal and no Discount row, as before' => function () use ($setup, $sell): void {
        $s = $setup();
        $sale = $sell($s, [['product_id' => $s['p'], 'unit_id' => $s['piece'], 'qty' => '2']], '', '5');
        $r = login_as('admin', TEST_ADMIN_PASSWORD)->get('sales/receipt', ['id' => $sale['id']])->body;
        assert_not_contains('>Discount<', $r);
        assert_not_contains('>Subtotal<', $r);
    },
];
