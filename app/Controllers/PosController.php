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
            'can' => ['wholesale' => Gate::allows('sale.wholesale'), 'discount' => Gate::allows('sale.discount'), 'belowCost' => (int) (Auth::user()['is_super'] ?? 0) === 1, 'override' => Gate::allows('sale.price_override'),
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
                'allow_short_drawer' => ($in['allow_short_drawer'] ?? false) === true,
            ]);
        } catch (\DomainException $e) {
            $this->json(['error' => $e->getMessage(), 'needs_pin' => str_starts_with($e->getMessage(), 'Needs an Admin PIN'),
                         'short_drawer' => $e->getCode() === \App\Services\CashService::SHORT_DRAWER], 422);
        }
        // The done screen says what really happened: cash, card or credit, and what a credit customer now owes.
        $paid = (new \App\Models\Sale())->paidByMethod((int) $result['id']);
        $customerId = (int) ($in['customer_id'] ?? 0);
        $c = $customerId > 0 ? (new Customer())->find($customerId) : null;
        $this->json($result + [
            'receipt'    => url('sales/receipt', ['id' => $result['id']]),
            'cash_usd'   => $paid['cash'],
            'card_usd'   => $paid['card'],
            'credit_usd' => $paid['credit'],
            'customer'   => $c === null ? null : [
                'name'    => $c['name'],
                'balance' => number_format((float) $c['balance_usd'], 2, '.', ''),
                'limit'   => $c['credit_limit_usd'] === null ? null : number_format((float) $c['credit_limit_usd'], 2, '.', ''),
            ],
        ]);
    }

    /** Which cart lines would be sold below cost (after line and invoice discounts). The cost itself is never sent. */
    public function check(): void
    {
        $in = $this->jsonInput();
        try {
            $low = (new SaleService())->belowCost([
                'price_level' => (string) ($in['price_level'] ?? 'retail'), 'lines' => is_array($in['lines'] ?? null) ? $in['lines'] : [],
                'invoice_discount' => (string) ($in['invoice_discount'] ?? ''),
            ]);
        } catch (\DomainException) {
            $low = [];   // an unfinished line (no quantity yet…): nothing to warn about
        }
        $this->json(['below_cost' => $low]);
    }

    /** The till checks an administrator's PIN as soon as a cashier asks for something restricted (a discount), not only at the end. */
    public function pin(): void
    {
        $in = $this->jsonInput();
        try {
            $approver = (new SaleService())->verifyPin(trim((string) ($in['pin'] ?? '')), [mb_substr(trim((string) ($in['for'] ?? 'approval')), 0, 120)]);
        } catch (\DomainException $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
        $this->json(['ok' => true, 'by' => $approver['username']]);
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
            $r = (new CashService())->collectDebt((int) ($in['customer_id'] ?? 0), (string) ($in['usd'] ?? ''), (string) ($in['lbp'] ?? ''), (int) $session['id'], Auth::id(),
                ($in['change_currency'] ?? 'LBP') === 'USD' ? 'USD' : 'LBP', ($in['allow_short_drawer'] ?? false) === true);
        } catch (\DomainException $e) {
            $this->json(['error' => $e->getMessage(), 'short_drawer' => $e->getCode() === CashService::SHORT_DRAWER], 422);
        }
        $this->json(['ok' => true, 'usd' => $r['paid_usd'], 'change_usd' => $r['change_usd'], 'change_lbp' => $r['change_lbp'], 'balance' => $r['balance']]);
    }

    // ---- Returns in the till (2026-10-04). Without return.create (the Cashier role since migration 007) the administrator's
    // PIN opens the pop-up and gives a 15-minute pass to search; saving the return checks the PIN again.

    private const RETURN_PASS_SECONDS = 900;

    /** The PIN that opens the return pop-up. Nothing is asked of a role that may process returns. */
    public function returnPin(): void
    {
        $in = $this->jsonInput();
        try {
            $approver = $this->approveReturn((string) ($in['pin'] ?? ''));
        } catch (\DomainException $e) {
            $this->json(['error' => $e->getMessage(), 'needs_pin' => true], 422);
        }
        $_SESSION['return_pass'] = time();
        $this->json(['ok' => true, 'approved_by' => $approver['username'] ?? null]);
    }

    public function returnSearch(): void
    {
        $this->requireReturnPass();
        $this->json(['sales' => array_map(static fn (array $s): array => [
            'id' => (int) $s['id'], 'invoice_no' => $s['invoice_no'], 'date' => date('d/m/Y H:i', strtotime($s['created_at'])),
            'customer' => $s['customer_name'], 'total' => $s['total_usd'], 'products' => $s['products'],
        ], (new \App\Models\Sale())->forReturn($this->query('q')))]);
    }

    public function returnSale(): void
    {
        $this->requireReturnPass();
        $sales = new \App\Models\Sale();
        $sale = $sales->find($this->queryInt('id'));
        if ($sale === null || $sale['status'] !== 'completed') {
            $this->json(['error' => 'This invoice cannot be returned.'], 404);
        }
        $lines = [];
        foreach ($sales->items((int) $sale['id']) as $i) {
            $left = (int) $i['base_qty'] - (int) $i['returned_base_qty'];
            $lines[] = [
                'id' => (int) $i['id'], 'name' => $i['product_name'], 'unit' => $i['unit_name'], 'factor' => (int) $i['factor'],
                'fraction' => (int) $i['allows_fraction'] === 1, 'base_qty' => (int) $i['base_qty'], 'paid' => $i['line_total_usd'],
                'sold' => \App\Services\Quantity::unitQty((int) $i['base_qty'], (int) $i['factor']),
                'left' => \App\Services\Quantity::unitQty(max(0, $left), (int) $i['factor']), 'left_base' => max(0, $left),
            ];
        }
        $this->json(['sale' => [
            'id' => (int) $sale['id'], 'invoice_no' => $sale['invoice_no'], 'date' => date('d/m/Y H:i', strtotime($sale['created_at'])),
            'customer' => $sale['customer_name'], 'total' => $sale['total_usd'],
            'owed' => $sale['customer_id'] !== null ? (new Customer())->balance((int) $sale['customer_id']) : '0.00',
        ], 'lines' => $lines]);
    }

    public function returnStore(): void
    {
        [$register, $session] = $this->requireSession();
        $in = $this->jsonInput();
        try {
            $approver = $this->approveReturn((string) ($in['pin'] ?? ''));
            $items = [];
            foreach (is_array($in['items'] ?? null) ? $in['items'] : [] as $it) {
                if (is_array($it)) {
                    $items[] = ['sale_item_id' => (int) ($it['sale_item_id'] ?? 0), 'qty' => (string) ($it['qty'] ?? ''), 'condition' => (string) ($it['condition'] ?? 'restock')];
                }
            }
            $r = (new \App\Services\ReturnService())->create((int) ($in['sale_id'] ?? 0), $items, 'MIX', (string) ($in['reason'] ?? ''), (int) $session['id'], (int) $register['id'],
                Auth::id(), ($in['allow_short_drawer'] ?? false) === true, (string) ($in['usd'] ?? ''), (string) ($in['lbp'] ?? ''));
        } catch (\DomainException $e) {
            $this->json(['error' => $e->getMessage(), 'needs_pin' => str_starts_with($e->getMessage(), 'Needs an Admin PIN') || $e->getMessage() === 'Wrong PIN.',
                         'short_drawer' => $e->getCode() === CashService::SHORT_DRAWER], 422);
        }
        if ($approver !== null) {
            \App\Core\Audit::log('pin.override', 'return', (int) $r['id'], ['approved_by' => $approver['username'], 'for' => ['return']]);
        }
        $this->json(['ok' => true, 'id' => $r['id'], 'return_no' => $r['return_no'], 'total_usd' => $r['total_usd'], 'cash_parts' => $r['cash_parts'],
                     'debt_reduction' => $r['debt_reduction'], 'receipt' => url('returns/receipt', ['id' => $r['id']])]);
    }

    /** @return array|null the approving administrator, or null when the user's role may process returns */
    private function approveReturn(string $pin): ?array
    {
        return Gate::allows('return.create') ? null : (new SaleService())->verifyPin($pin, ['return']);
    }

    private function requireReturnPass(): void
    {
        if (!Gate::allows('return.create') && time() - (int) ($_SESSION['return_pass'] ?? 0) > self::RETURN_PASS_SECONDS) {
            $this->json(['error' => 'The administrator PIN is needed to open a return.', 'needs_pin' => true], 403);
        }
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
