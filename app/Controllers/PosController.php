<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Gate;
use App\Core\RegisterDevice;
use App\Core\Settings;
use App\Core\View;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Barcode;
use App\Services\CashService;
use App\Services\Money;
use App\Services\ProductImageService;
use App\Services\SaleService;

/** The till. The page is one HTML file + app JS; everything else is JSON. */
final class PosController extends Controller
{
    public function index(): void
    {
        $register = RegisterDevice::current();
        $session = $register === null ? null : (new CashSession())->openForRegister((int) $register['id']);
        $mine = (new CashSession())->openForUser(Auth::id());
        if ($register === null || $session === null || $mine === null || (int) $mine['id'] !== (int) $session['id']) {
            $active = array_values(array_filter((new \App\Models\Register())->all(), static fn (array $r): bool => (int) $r['is_active'] === 1));
            $this->render('pos/blocked', ['register' => $register, 'session' => $session, 'mine' => $mine, 'registers' => $active], 'Till');

            return;
        }
        View::render('pos/index', [
            'pageTitle' => 'Till', 'register' => $register, 'session' => $session, 'user' => Auth::user(), 'token' => Csrf::token(),
            'rate' => (new ExchangeRate())->current(), 'step' => Money::step(),
            'can' => ['wholesale' => Gate::allows('sale.wholesale'), 'discount' => Gate::allows('sale.discount'), 'override' => Gate::allows('sale.price_override'),
                      'credit' => Gate::allows('sale.credit'), 'debt' => Gate::allows('debt.collect'), 'void' => Gate::allows('sale.void'), 'returns' => Gate::allows('return.create')],
            'maxDiscount' => Settings::get('max_cashier_discount_pct', '0'),
        ], null);
    }

    /** Catalog for the grid: active products with sellable units and barcodes. */
    public function data(): void
    {
        $products = (new Product())->forPrint();
        $units = (new ProductUnit())->forProducts(array_map('intval', array_column($products, 'id')));
        $barcodes = [];
        foreach (\App\Core\Database::pdo()->query('SELECT b.barcode, b.product_unit_id, u.product_id FROM barcodes b JOIN product_units u ON u.id = b.product_unit_id') as $b) {
            $barcodes[$b['barcode']] = ['p' => (int) $b['product_id'], 'u' => (int) $b['product_unit_id']];
        }
        $out = [];
        foreach ($products as $p) {
            $out[] = [
                'id' => (int) $p['id'], 'name' => $p['name'], 'code' => $p['internal_code'], 'cat' => (int) ($p['category_id'] ?? 0), 'color' => $p['category_color'],
                'grid' => (int) $p['show_on_pos_grid'] === 1, 'base' => $p['base_unit'], 'stock' => (int) $p['stock_base'], 'override' => (int) $p['allow_price_override'] === 1,
                'img' => ProductImageService::url($p['image_file']),
                'units' => array_values(array_map(static fn (array $u): array => [
                    'id' => (int) $u['id'], 'name' => $u['name'], 'factor' => (int) $u['factor'], 'fraction' => (int) $u['allows_fraction'] === 1,
                    'retail' => $u['retail_price'], 'wholesale' => $u['wholesale_price'], 'default' => (int) $u['is_default_sale'] === 1,
                ], $units[(int) $p['id']] ?? [])),
            ];
        }
        $this->json([
            'products' => $out, 'barcodes' => $barcodes,
            'categories' => array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'name' => $c['name'], 'color' => $c['color']], (new Category())->all(true)),
            'rate' => (new ExchangeRate())->current(), 'step' => Money::step(),
        ]);
    }

    public function customers(): void
    {
        $rows = (new Customer())->search($this->query('q'));
        $this->json(['customers' => array_map(static fn (array $c): array => [
            'id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'level' => $c['default_price_level'], 'balance' => $c['balance_usd'], 'limit' => $c['credit_limit_usd'],
        ], $rows)]);
    }

    public function complete(): void
    {
        [$register, $session] = $this->requireSession();
        $in = $this->jsonInput();
        try {
            $result = (new SaleService())->complete([
                'register_id' => (int) $register['id'], 'session_id' => (int) $session['id'], 'user_id' => Auth::id(),
                'customer_id' => (int) ($in['customer_id'] ?? 0) ?: null, 'price_level' => (string) ($in['price_level'] ?? 'retail'),
                'lines' => is_array($in['lines'] ?? null) ? $in['lines'] : [], 'invoice_discount' => (string) ($in['invoice_discount'] ?? ''),
                'payments' => is_array($in['payments'] ?? null) ? $in['payments'] : [], 'change_currency' => (string) ($in['change_currency'] ?? 'LBP'),
                'notes' => (string) ($in['notes'] ?? ''), 'pin' => (string) ($in['pin'] ?? ''),
            ]);
        } catch (\DomainException $e) {
            $this->json(['error' => $e->getMessage(), 'needs_pin' => str_starts_with($e->getMessage(), 'Needs an Admin PIN')], 422);
        }
        $this->json($result + ['receipt' => url('sales/receipt', ['id' => $result['id']])]);
    }

    public function hold(): void
    {
        [$register] = $this->requireSession();
        $in = $this->jsonInput();
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60) ?: date('H:i');
        $cart = $in['cart'] ?? null;
        if (!is_array($cart) || $cart === []) {
            $this->json(['error' => 'The cart is empty.'], 422);
        }
        $id = (new HeldSale())->create((int) $register['id'], Auth::id(), $name, (string) json_encode($cart, JSON_UNESCAPED_UNICODE));
        $this->json(['id' => $id]);
    }

    public function held(): void
    {
        [$register] = $this->requireSession();
        $rows = (new HeldSale())->forRegister((int) $register['id']);
        $this->json(['held' => array_map(static fn (array $h): array => ['id' => (int) $h['id'], 'name' => $h['name'], 'by' => $h['username'], 'at' => $h['created_at'], 'cart' => json_decode((string) $h['cart_json'], true)], $rows)]);
    }

    public function resume(): void
    {
        $this->requireSession();
        $in = $this->jsonInput();
        $held = new HeldSale();
        $row = $held->find((int) ($in['id'] ?? 0));
        if ($row === null) {
            $this->json(['error' => 'Held sale not found.'], 404);
        }
        $held->delete((int) $row['id']);
        $this->json(['cart' => json_decode((string) $row['cart_json'], true)]);
    }

    public function debt(): void
    {
        [, $session] = $this->requireSession();
        $in = $this->jsonInput();
        try {
            $usd = (new CashService())->collectDebt((int) ($in['customer_id'] ?? 0), (string) ($in['currency'] ?? 'USD'), (string) ($in['amount'] ?? ''), (int) $session['id'], Auth::id());
        } catch (\DomainException $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
        $this->json(['ok' => true, 'usd' => $usd, 'balance' => (new Customer())->balance((int) $in['customer_id'])]);
    }

    /** @return array{0: array, 1: array} register and this user's open session on it */
    private function requireSession(): array
    {
        $register = RegisterDevice::current();
        $session = $register === null ? null : (new CashSession())->openForRegister((int) $register['id']);
        if ($register === null || $session === null || (int) $session['user_id'] !== Auth::id()) {
            $this->json(['error' => 'No open session on this register for you. Open one first.'], 409);
        }

        return [$register, $session];
    }
}
