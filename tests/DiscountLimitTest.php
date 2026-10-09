<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Settings;
use App\Models\Register;
use App\Models\Setting;
use App\Models\User;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\RoleService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * Owner's note 9 (2026-10-09): the cashier's limit is 10 %, yet a 20 % discount sometimes went through without the PIN.
 * The limit was measured on the whole cart, so 20 % on a $15 kg hid behind a $280 box. Now every single discount is
 * held to the ceiling: each line (a lowered price counts), the invoice discount, and the sale as a whole.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $charcoal = make_product('فحم', 'g');
    $kg = $svc->addUnit($charcoal, 'kg', '1000', true, false);
    $box = $svc->addUnit($charcoal, 'Box', '20000', false, true);
    $svc->setPrices($kg, '15', '13');
    $svc->setPrices($box, '280', '250');
    $svc->setCost($charcoal, '100', 20000);   // $5 per kg: nothing here goes below cost
    (new StockService())->adjust($charcoal, $box, '10', 'opening', '', null, TEST_ADMIN_ID);
    $registerId = (new Register())->create('Register 01');
    $sessionId = (new CashService())->open($registerId, TEST_ADMIN_ID, '1000', '0');
    (new Setting())->setMany(['max_cashier_discount_pct' => '10']);
    Settings::flush();
    (new User())->setPin(TEST_ADMIN_ID, '2468');

    return ['charcoal' => $charcoal, 'kg' => $kg, 'box' => $box, 'register' => $registerId, 'session' => $sessionId];
};
$sale = static fn (array $s, array $lines, string $amount, array $extra = []): array => (new SaleService())->complete([
    'register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => Auth::id(), 'customer_id' => null,
    'price_level' => 'retail', 'lines' => $lines, 'invoice_discount' => $extra['invoice_discount'] ?? '',
    'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => $amount]], 'change_currency' => 'USD', 'notes' => '', 'pin' => $extra['pin'] ?? '',
]);
$box = static fn (array $s): array => ['product_id' => $s['charcoal'], 'unit_id' => $s['box'], 'qty' => '1'];
$kg = static fn (array $s, string $discount = '', ?string $price = null): array => ['product_id' => $s['charcoal'], 'unit_id' => $s['kg'], 'qty' => '1', 'discount' => $discount, 'price' => $price];

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    '20 % on one line needs the PIN even when the cart makes it 1 %; 10 % on the line does not' => function () use ($setup, $sale, $box, $kg): void {
        $s = $setup();
        Auth::login(make_user('cashier1'));
        Gate::forget();
        $e = assert_throws(DomainException::class, fn () => $sale($s, [$box($s), $kg($s, '3')], '292'));   // $3 off $15 = 20 %, 1 % of $295
        assert_contains('Needs an Admin PIN: discount of 20.0% on فحم', $e->getMessage());
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        $sale($s, [$box($s), $kg($s, '1.5')], '293.5');   // 10 %: allowed
        $sale($s, [$box($s), $kg($s, '3')], '292', ['pin' => '2468']);   // 20 % with the administrator
        assert_same(2, (int) Database::pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    },

    'the invoice discount is held to the same ceiling, and so is the sale as a whole when both stack' => function () use ($setup, $sale, $box, $kg): void {
        $s = $setup();
        Auth::login(make_user('cashier2'));
        Gate::forget();
        $sale($s, [$box($s), $kg($s)], '265.5', ['invoice_discount' => '29.5']);   // 10 % of $295
        $e = assert_throws(DomainException::class, fn () => $sale($s, [$box($s), $kg($s)], '265', ['invoice_discount' => '30']));
        assert_contains('invoice discount of 10.2%', $e->getMessage());
        // 10 % on the kg line, then 10 % on the invoice: each inside the ceiling, 19 % together
        $e = assert_throws(DomainException::class, fn () => $sale($s, [$box($s), $kg($s, '1.5')], '264.15', ['invoice_discount' => '29.35']));
        assert_contains('discount of 10.5%', $e->getMessage());   // (1.5 + 29.35) / 295
    },

    'a price lowered by hand counts as a discount for a user who may change prices but not discount' => function () use ($setup, $sale, $kg): void {
        $s = $setup();
        Database::pdo()->exec("UPDATE products SET allow_price_override = 1 WHERE id = {$s['charcoal']}");
        $roles = new RoleService();
        $role = $roles->create('Price changer');
        $roles->setPermissions($role, ['pos.use', 'sale.create', 'sale.price_override']);
        Auth::login(make_user('pricer', $role));
        Gate::forget();
        $e = assert_throws(DomainException::class, fn () => $sale($s, [$kg($s, '', '12')], '12'));   // $15 → $12 is 20 % off
        assert_contains('Needs an Admin PIN: discount of 20.0% on فحم', $e->getMessage());
        $sale($s, [$kg($s, '', '14')], '14');   // 6.7 %: allowed
        $sale($s, [$kg($s, '', '16')], '16');   // a higher price is no discount
        assert_same(2, (int) Database::pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    },

    'a user with the discount permission is never asked' => function () use ($setup, $sale, $box, $kg): void {
        $s = $setup();
        $sale($s, [$box($s), $kg($s, '7.5')], '287.5');   // 50 % on the line, by the administrator
        assert_same(1, (int) Database::pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    },
];
