<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Register;
use App\Models\Sale;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-10-04 (Aya): a cash refund can be given in USD, in LBP, or both: the cashier types the USD part and the rest
 * goes in LBP, rounded to 5,000 like change, with the rounding recorded on the return.
 */
$setup = static function (string $price): array {
    $p = make_product('Fumari White Gummi Bear');
    $pack = (new ProductService())->addUnit($p, 'Pack', '1', false, true);
    (new ProductService())->setPrices($pack, $price, '');
    (new StockService())->adjust($p, $pack, '10', 'opening', '', '5', TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '1000000');
    $sale = (new SaleService())->complete([
        'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
        'lines' => [['product_id' => $p, 'unit_id' => $pack, 'qty' => '1']], 'invoice_discount' => '',
        'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => $price]], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
    ]);

    return ['register' => $register, 'session' => $session, 'sale' => (int) $sale['id']];
};
$giveBack = static function (array $s, string $currency, ?string $usdPart = null, ?string $lbpPart = null): array {
    $item = (new Sale())->items($s['sale'])[0];

    return (new ReturnService())->create($s['sale'], [['sale_item_id' => (int) $item['id'], 'qty' => '1', 'condition' => 'restock']], $currency, '', $s['session'], $s['register'], TEST_ADMIN_ID, false, $usdPart, $lbpPart);
};
$refunds = static fn (int $returnId): array => Database::pdo()->query("SELECT method, currency, amount, amount_usd FROM return_refunds WHERE return_id = {$returnId} ORDER BY id")->fetchAll();

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    '$9.50 back as $5 + 405,000 LBP: two refund lines, both currencies leave the drawer' => function () use ($setup, $giveBack, $refunds): void {
        $s = $setup('9.50');
        $ret = $giveBack($s, 'MIX', '5');
        assert_same([
            ['method' => 'cash', 'currency' => 'USD', 'amount' => '5.00', 'amount_usd' => '5.00'],
            ['method' => 'cash', 'currency' => 'LBP', 'amount' => '405000.00', 'amount_usd' => '4.50'],
        ], $refunds((int) $ret['id']));
        assert_same('0.00', $ret['rounding_usd']);
        // drawer: $100 + $9.50 sale − $5 = $104.50; 1,000,000 − 405,000 = 595,000 LBP
        assert_same(['USD' => '104.50', 'LBP' => '595000'], (new CashSession())->expected($s['session']));
    },

    'the LBP part is rounded to 5,000 and the rounding is recorded ($7.24 = $5 + 200,000 LBP, +$0.02)' => function () use ($setup, $giveBack, $refunds): void {
        $s = $setup('7.24');
        $ret = $giveBack($s, 'MIX', '5');
        assert_same('200000.00', $refunds((int) $ret['id'])[1]['amount']);
        assert_same('0.02', $ret['rounding_usd']);
    },

    'LBP typed alone: the rest goes in USD ($9.50 = 450,000 LBP + $4.50)' => function () use ($setup, $giveBack, $refunds): void {
        $s = $setup('9.50');
        $ret = $giveBack($s, 'MIX', '', '450,000');
        $rows = $refunds((int) $ret['id']);
        assert_same([['USD', '4.50'], ['LBP', '450000.00']], array_map(static fn (array $r): array => [$r['currency'], $r['amount']], $rows));
    },

    'LBP alone that covers it after the 5,000 rounding is all LBP, the cents are rounding ($7.24 = 650,000 LBP, +$0.02)' => function () use ($setup, $giveBack, $refunds): void {
        $s = $setup('7.24');
        $ret = $giveBack($s, 'MIX', '', '650,000');
        assert_same(1, count($refunds((int) $ret['id'])));
        assert_same('0.02', $ret['rounding_usd']);
    },

    'both amounts typed must add up to the refund' => function () use ($setup, $giveBack, $refunds): void {
        $s = $setup('9.50');
        assert_contains('must add up to the $9.50', assert_throws(DomainException::class, fn () => $giveBack($s, 'MIX', '5', '100,000'))->getMessage());
        $ret = $giveBack($s, 'MIX', '5', '405,000');
        assert_same(2, count($refunds((int) $ret['id'])));
    },

    'nothing typed, or more than the refund, is refused; all in USD is fine' => function () use ($setup, $giveBack, $refunds): void {
        $s = $setup('9.50');
        assert_contains('Type how much you give back', assert_throws(DomainException::class, fn () => $giveBack($s, 'MIX', '', ''))->getMessage());
        assert_contains('more than the $9.50', assert_throws(DomainException::class, fn () => $giveBack($s, 'MIX', '20', ''))->getMessage());
        $ret = $giveBack($s, 'MIX', '9.50', '');
        assert_same([['USD', '9.50']], array_map(static fn (array $r): array => [$r['currency'], $r['amount']], $refunds((int) $ret['id'])));
    },

    'a short drawer is checked on each currency of a split' => function () use ($setup, $giveBack): void {
        $s = $setup('30');
        // $5 in USD is fine, but $25 in LBP is 2,250,000 and the drawer has 1,000,000
        $e = assert_throws(DomainException::class, fn () => $giveBack($s, 'MIX', '5'));
        assert_same(CashService::SHORT_DRAWER, $e->getCode());
        assert_contains('2,250,000 LBP', $e->getMessage());
    },

    'the returns page has two amounts, like expenses, and records the split' => function () use ($setup): void {
        $s = $setup('9.50');
        $itemId = (int) (new Sale())->items($s['sale'])[0]['id'];
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $page = $client->get('returns', ['invoice' => 'INV-000001'])->body;
        assert_contains('Give back in USD', $page);
        assert_contains('name="lbp_part"', $page);
        assert_not_contains('<select class="form-select" id="cash_currency"', $page, 'no currency list any more');
        $r = $client->post('returns/store', ['sale_id' => (string) $s['sale'], "items[{$itemId}][qty]" => '1', "items[{$itemId}][condition]" => 'restock',
            'cash_currency' => 'MIX', 'usd_part' => '5', 'lbp_part' => '405,000', 'reason' => '']);
        assert_same(302, $r->status);
        $view = $client->get('returns/view', ['id' => 1])->body;
        assert_contains('$5.00 + 405,000 LBP', $view);
    },
];
