<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Settings;
use App\Models\CashSession;
use App\Models\Counter;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\User;

/**
 * Completing sales (spec §5–§7; voids removed 2026-10-05, a sale is undone with a return). One transaction touches sales, sale_items,
 * sale_payments, stock_movements, cash_movements, customer_ledger, counters and audit_log.
 */
final class SaleService
{
    /**
     * @param array{
     *   register_id: int, session_id: int, user_id: int, customer_id: ?int, price_level: string,
     *   lines: list<array{product_id: int, unit_id: int, qty?: string, amount_usd?: string, price?: ?string, discount?: string}>,
     *   invoice_discount: string, payments: list<array{method: string, currency: string, amount: string}>,
     *   change_currency: string, notes: string, pin: string
     * } $in
     * @return array{id: int, invoice_no: string, change_usd: string, change_lbp: int, warnings: list<string>}
     */
    public function complete(array $in): array
    {
        $rate = (new ExchangeRate())->current();
        $step = Money::step();
        $level = $in['price_level'] === 'wholesale' ? 'wholesale' : 'retail';
        $needsPin = [];
        $warnings = [];
        if ($level === 'wholesale' && !Gate::allows('sale.wholesale')) {
            $needsPin[] = 'wholesale prices';
        }
        $customer = null;
        if (!empty($in['customer_id'])) {
            $customer = (new Customer())->find((int) $in['customer_id']) ?? throw new \DomainException('Customer not found.');
        }

        ['lines' => $lines, 'subtotal' => $subtotal, 'invoiceDiscount' => $invoiceDiscount, 'total' => $total, 'listGross' => $listGross, 'cutTotal' => $cutTotal, 'worst' => $worst]
            = $this->priceLines($in, $level, $needsPin);
        $low = $this->belowCostLines($lines);
        $belowCost = array_values(array_unique(array_column($low, 'name')));
        // A discount or a changed price that takes a line under its cost is the administrator's call, whatever the percentage.
        // A list price that is already under cost is only warned about: the cashier did nothing to cause it.
        $lowered = array_values(array_unique(array_column(array_filter($low, static fn (array $l): bool => $l['lowered']), 'name')));
        if ($lowered !== [] && (int) (Auth::user()['is_super'] ?? 0) !== 1) {
            $needsPin[] = 'sale below cost: ' . implode(', ', $lowered);
        }
        foreach ($belowCost as $name) {
            $warnings[] = $name . ' was sold below cost.';
        }
        // The allowed percentage is a ceiling on every single discount (bug of 2026-10-09: measured on the whole cart, 20 % on a $10
        // pack hid behind a $120 hookah). It is checked on each line (a lowered price counts as a discount), on the invoice
        // discount, and on the sale as a whole; the first one over the ceiling needs the administrator.
        if (!Gate::allows('sale.discount')) {
            $maxPct = (float) Settings::get('max_cashier_discount_pct', '0');
            $checks = [];
            if ($worst['pct'] > 0) {
                $checks[] = [$worst['pct'], sprintf('discount of %.1f%% on %s', $worst['pct'], $worst['name'])];
            }
            $invoicePct = (float) $subtotal > 0 ? (float) $invoiceDiscount / (float) $subtotal * 100 : 0;
            if ($invoicePct > 0) {
                $checks[] = [$invoicePct, sprintf('invoice discount of %.1f%%', $invoicePct)];
            }
            $overallPct = $listGross > 0 ? ($cutTotal + (float) $invoiceDiscount) / $listGross * 100 : 0;
            if ($overallPct > 0) {
                $checks[] = [$overallPct, sprintf('discount of %.1f%%', $overallPct)];
            }
            foreach ($checks as [$pct, $what]) {
                if ($pct > $maxPct + 0.0001) {
                    $needsPin[] = $what;
                    break;
                }
            }
        }

        // Payments (§7.1)
        $payments = [];
        $paid = 0.0;
        $credit = 0.0;
        foreach ($in['payments'] as $p) {
            $method = (string) ($p['method'] ?? '');
            $currency = (string) ($p['currency'] ?? 'USD');
            if ($method === 'cash' && $currency === 'LBP') {
                $amount = (string) CashService::parseLbp((string) $p['amount'], false);
                $usd = Money::lbpToUsd((int) $amount, $rate);
            } elseif (in_array($method, ['cash', 'card', 'credit'], true)) {
                $currency = 'USD';
                $amount = Pricing::parse((string) $p['amount']);
                $usd = $amount;
            } else {
                throw new \DomainException('Unknown payment method.');
            }
            if ((float) $usd <= 0) {
                continue;
            }
            if ($method === 'credit') {
                if ($customer === null) {
                    throw new \DomainException('Credit needs a customer on the sale.');
                }
                if (!Gate::allows('sale.credit')) {
                    $needsPin[] = 'sale on credit';
                }
                $credit += (float) $usd;
            }
            $payments[] = ['method' => $method, 'currency' => $currency, 'amount' => $amount, 'amount_usd' => $usd];
            $paid += (float) $usd;
        }
        if ($credit > 0 && $customer !== null && $customer['credit_limit_usd'] !== null
            && (float) $customer['balance_usd'] + $credit > (float) $customer['credit_limit_usd'] + 0.004) {
            $needsPin[] = 'credit limit of ' . usd($customer['credit_limit_usd']);
        }

        // Settle: tolerance, change and rounding (§7.2, §7.3)
        $remaining = round((float) $total - $paid, 2);
        $tolerance = ($step / 2) / $rate;
        $rounding = 0.0;
        $changeUsd = '0.00';
        $changeLbp = 0;
        if ($remaining > 0.004) {
            if ($remaining <= $tolerance + 0.000001) {
                $rounding = -$remaining;   // shop loss of a few hundred lira
            } else {
                throw new \DomainException('Not fully paid: ' . usd($remaining) . ' (' . lbp(Money::roundLbp(Money::usdToLbp(Money::fmt($remaining), $rate), $step)) . ') is still due.');
            }
        } elseif ($remaining < -0.004) {
            $over = -$remaining;
            if ($credit > 0 && $paid - $credit < (float) $total - 0.004) {
                throw new \DomainException('Credit cannot exceed the amount due.');
            }
            $changeCurrency = (string) ($in['change_currency'] ?? 'LBP');
            if ($changeCurrency === 'USD') {
                $whole = floor($over);
                $rest = round($over - $whole, 2);
                $changeUsd = Money::fmt($whole);
                if ($rest > 0.004) {
                    $exact = Money::usdToLbp(Money::fmt($rest), $rate);
                    $changeLbp = Money::roundLbp($exact, $step);
                    $rounding = round($rest - (float) Money::lbpToUsd($changeLbp, $rate), 2);
                }
            } else {
                $exact = Money::usdToLbp(Money::fmt($over), $rate);
                $changeLbp = Money::roundLbp($exact, $step);
                $rounding = round($over - (float) Money::lbpToUsd($changeLbp, $rate), 2);
            }
        }

        // Change must come out of a drawer that has it; lira the customer just handed over counts (I5, warn and allow).
        $cashIn = ['USD' => 0.0, 'LBP' => 0.0];
        foreach ($payments as $p) {
            if ($p['method'] === 'cash') {
                $cashIn[$p['currency']] += (float) $p['amount'];
            }
        }
        $short = CashService::drawerShortfall((int) $in['session_id'], ['USD' => $changeUsd, 'LBP' => $changeLbp], $cashIn, 'change');
        if ($short !== null && empty($in['allow_short_drawer'])) {
            throw new \DomainException($short, CashService::SHORT_DRAWER);
        }

        // Admin PIN for anything the cashier may not do alone (§15)
        $approver = null;
        if ($needsPin !== []) {
            $approver = $this->verifyPin((string) ($in['pin'] ?? ''), $needsPin);
        }

        $stockService = new StockService();
        $result = Database::transaction(function () use ($in, $lines, $payments, $customer, $level, $subtotal, $invoiceDiscount, $total, $rounding, $rate, $changeUsd, $changeLbp, $stockService, $credit, &$warnings, $needsPin, $approver): array {
            $no = Counter::format('INV-', Counter::next('invoice'));
            $sales = new Sale();
            $saleId = $sales->create([
                'invoice_no' => $no, 'register_id' => $in['register_id'], 'session_id' => $in['session_id'], 'user_id' => $in['user_id'],
                'customer_id' => $customer === null ? null : (int) $customer['id'], 'price_level' => $level, 'subtotal_usd' => Money::fmt($subtotal),
                'discount_usd' => $invoiceDiscount, 'total_usd' => $total, 'rounding_usd' => Money::fmt($rounding),
                'cost_total_usd' => Money::fmt(array_sum(array_map(static fn (array $l): float => (float) $l['line_cost_usd'], $lines))),
                'exchange_rate' => $rate, 'change_usd' => $changeUsd, 'change_lbp' => $changeLbp, 'notes' => trim((string) ($in['notes'] ?? '')) ?: null,
            ]);
            foreach ($lines as $l) {
                $sales->addItem($saleId, $l);
                if ($l['stock_base'] - $l['base_qty'] < 0) {
                    $warnings[] = $l['product_name'] . ' is now below zero stock.';
                }
                $stockService->move($l['product_id'], -$l['base_qty'], 'sale', null, $l['cost_per_base'], 'sale', $saleId, $in['user_id'], $in['session_id']);
            }
            $cash = new CashSession();
            foreach ($payments as $p) {
                $sales->addPayment($saleId, $p['method'], $p['currency'], $p['amount'], $p['amount_usd']);
                if ($p['method'] === 'cash') {
                    $cash->addMovement(['session_id' => $in['session_id'], 'currency' => $p['currency'], 'amount' => $p['amount'], 'type' => 'sale',
                                        'ref_type' => 'sale', 'ref_id' => $saleId, 'user_id' => $in['user_id']]);
                }
            }
            if ((float) $changeUsd > 0) {
                $cash->addMovement(['session_id' => $in['session_id'], 'currency' => 'USD', 'amount' => '-' . $changeUsd, 'type' => 'change', 'ref_type' => 'sale', 'ref_id' => $saleId, 'user_id' => $in['user_id']]);
            }
            if ($changeLbp > 0) {
                $cash->addMovement(['session_id' => $in['session_id'], 'currency' => 'LBP', 'amount' => '-' . $changeLbp, 'type' => 'change', 'ref_type' => 'sale', 'ref_id' => $saleId, 'user_id' => $in['user_id']]);
            }
            if ($credit > 0 && $customer !== null) {
                (new Customer())->addLedger(['customer_id' => (int) $customer['id'], 'type' => 'sale_credit', 'amount_usd' => Money::fmt($credit), 'currency' => 'USD',
                                             'amount_original' => Money::fmt($credit), 'exchange_rate' => $rate, 'sale_id' => $saleId, 'session_id' => $in['session_id'], 'user_id' => $in['user_id'], 'note' => $no]);
            }
            Audit::log('sale.completed', 'sale', $saleId, ['no' => $no, 'lines' => count($lines), 'level' => $level], (float) $total, 'USD');
            if ($approver !== null) {
                Audit::log('pin.override', 'sale', $saleId, ['approved_by' => $approver['username'], 'for' => $needsPin]);
            }
            foreach ($lines as $l) {
                if ($l['price_overridden']) {
                    Audit::log('sale.price_override', 'sale', $saleId, ['product' => $l['product_name'], 'price' => $l['unit_price_usd']]);
                }
            }

            return ['id' => $saleId, 'invoice_no' => $no];
        });

        if ($belowCost !== []) {
            Audit::log('sale.below_cost', 'sale', (int) $result['id'], ['no' => $result['invoice_no'], 'products' => $belowCost]);
        }
        if ($short !== null) {   // confirmed by the cashier: the owner sees who gave change the drawer did not hold
            Audit::log('drawer.short', 'sale', (int) $result['id'], ['no' => $result['invoice_no'], 'warning' => $short]);
        }

        return $result + ['change_usd' => $changeUsd, 'change_lbp' => $changeLbp, 'warnings' => $warnings, 'total_usd' => $total, 'rounding_usd' => Money::fmt($rounding)];
    }


    /** An Admin types their PIN on the till to approve one action. Returns the approving user. */
    /**
     * Prices the cart: list or changed prices and line discounts, then the invoice discount spread across the lines (§6).
     * Adds to $needsPin what the signed-in user may not do alone.
     *
     * @return array{lines: list<array<string, mixed>>, gross: float, lineDiscounts: float, subtotal: float, invoiceDiscount: string, total: string}
     */
    private function priceLines(array $in, string $level, array &$needsPin): array
    {
        // Lines
        $units = new ProductUnit();
        $products = new Product();
        $lines = [];
        $gross = 0.0;
        $lineDiscounts = 0.0;
        $listGross = 0.0;   // what the lines would cost at the list price
        $cutTotal = 0.0;    // what the cashier took off them: line discounts and lowered prices
        $worst = ['pct' => 0.0, 'name' => ''];
        foreach ($in['lines'] as $l) {
            $unit = $units->find((int) ($l['unit_id'] ?? 0));
            if ($unit === null || (int) $unit['product_id'] !== (int) ($l['product_id'] ?? 0)) {
                throw new \DomainException('A line refers to an unknown product or unit.');
            }
            $product = $products->find((int) $unit['product_id']);
            if ($product === null || (int) $product['is_active'] !== 1) {
                throw new \DomainException('Product ' . ($product['name'] ?? '') . ' is not active.');
            }
            $listPrice = $level === 'wholesale' ? $unit['wholesale_price'] : $unit['retail_price'];
            if ($listPrice === null || (float) $listPrice <= 0) {
                throw new \DomainException("{$product['name']} ({$unit['name']}) is not sold at the {$level} price.");
            }
            $price = $listPrice;
            $overridden = false;
            if (isset($l['price']) && $l['price'] !== null && trim((string) $l['price']) !== '') {
                $override = Pricing::parse((string) $l['price']);
                if (Money::cmp($override, $listPrice) !== 0) {
                    if ((int) $product['allow_price_override'] !== 1) {
                        throw new \DomainException("{$product['name']} does not allow a price override.");
                    }
                    if (!Gate::allows('sale.price_override')) {
                        $needsPin[] = 'price override on ' . $product['name'];
                    }
                    $price = $override;
                    $overridden = true;
                }
            }
            $factor = (int) $unit['factor'];
            $fraction = (int) $unit['allows_fraction'] === 1;
            if (isset($l['amount_usd']) && trim((string) $l['amount_usd']) !== '') {
                // Sell by amount (§4): the total stays what the customer pays; grams round down.
                $amount = Pricing::parse((string) $l['amount_usd']);
                $base = (int) floor((float) $amount / ((float) $price / $factor));
                if ($base <= 0) {
                    throw new \DomainException('The amount is too small for one ' . $product['base_unit'] . ' of ' . $product['name'] . '.');
                }
                $qty = Quantity::unitQty($base, $factor);
                $lineGross = $amount;
                $mode = 'amount';
            } else {
                $qty = Quantity::parse((string) ($l['qty'] ?? '1'), $fraction);
                if ((float) $qty <= 0) {
                    throw new \DomainException('Enter a quantity for ' . $product['name'] . '.');
                }
                $base = Quantity::toBase($qty, $factor, $fraction);
                $lineGross = Money::mul($price, (float) $qty);
                $mode = 'qty';
            }
            $discount = Pricing::parse((string) ($l['discount'] ?? ''), true) ?? '0.00';
            if (Money::cmp($discount, $lineGross) > 0) {
                throw new \DomainException('A line discount cannot exceed the line total.');
            }
            $lineTotal = Money::sub($lineGross, $discount);
            $gross += (float) $lineGross;
            $lineDiscounts += (float) $discount;
            $atList = (float) Money::mul($listPrice, (float) $qty);
            $cut = max(0.0, $atList - (float) $lineTotal);
            $listGross += $atList;
            $cutTotal += $cut;
            if ($atList > 0 && $cut / $atList * 100 > $worst['pct']) {
                $worst = ['pct' => $cut / $atList * 100, 'name' => $product['name']];
            }
            $lines[] = [
                'product_id' => (int) $product['id'], 'product_unit_id' => (int) $unit['id'], 'product_name' => $product['name'], 'unit_name' => $unit['name'],
                'qty' => $qty, 'base_qty' => $base, 'unit_price_usd' => $price, 'line_discount_usd' => $discount, 'line_total_usd' => $lineTotal,
                'cost_per_base' => $product['cost_per_base'], 'line_cost_usd' => Money::fmt($base * (float) $product['cost_per_base']),
                'entry_mode' => $mode, 'price_overridden' => $overridden, 'stock_base' => (int) $product['stock_base'],
            ];
        }
        if ($lines === []) {
            throw new \DomainException('The cart is empty.');
        }

        // Invoice discount, spread across lines in proportion (§6)
        $subtotal = array_sum(array_map(static fn (array $l): float => (float) $l['line_total_usd'], $lines));
        $invoiceDiscount = Pricing::parse((string) ($in['invoice_discount'] ?? ''), true) ?? '0.00';
        if ((float) $invoiceDiscount > $subtotal + 0.0001) {
            throw new \DomainException('The discount cannot exceed the subtotal.');
        }
        $spread = 0.0;
        $last = count($lines) - 1;
        foreach ($lines as $i => &$line) {
            $share = $i === $last
                ? (float) $invoiceDiscount - $spread
                : ($subtotal > 0 ? round((float) $invoiceDiscount * (float) $line['line_total_usd'] / $subtotal, 2) : 0.0);
            $spread += $share;
            $line['line_discount_usd'] = Money::fmt((float) $line['line_discount_usd'] + $share);
            $line['line_total_usd'] = Money::fmt((float) $line['line_total_usd'] - $share);
        }
        unset($line);
        $total = Money::fmt($subtotal - (float) $invoiceDiscount);

        return ['lines' => $lines, 'gross' => $gross, 'lineDiscounts' => $lineDiscounts, 'subtotal' => $subtotal, 'invoiceDiscount' => $invoiceDiscount, 'total' => $total,
            'listGross' => $listGross, 'cutTotal' => $cutTotal, 'worst' => $worst];
    }

    /**
     * The lines whose amount after every discount is under their cost, as [i => position in the cart, name,
     * lowered => a discount or a changed price did it (and not the list price alone)].
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array{i: int, name: string, lowered: bool}>
     */
    private function belowCostLines(array $lines): array
    {
        $low = [];
        foreach ($lines as $i => $l) {
            if ((float) $l['line_cost_usd'] > 0 && (float) $l['line_total_usd'] < (float) $l['line_cost_usd'] - 0.004) {
                $low[] = ['i' => $i, 'name' => (string) $l['product_name'], 'lowered' => (float) $l['line_discount_usd'] > 0.004 || (bool) $l['price_overridden']];
            }
        }

        return $low;
    }

    /**
     * For the till, before the sale is completed: which lines would be sold below cost. It answers yes or no per line
     * and never the cost itself, so a cashier who may not see costs still gets the warning.
     *
     * @return list<array{i: int, name: string, lowered: bool}>
     */
    public function belowCost(array $in): array
    {
        $needsPin = [];
        $level = ($in['price_level'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
        $in['lines'] = is_array($in['lines'] ?? null) ? $in['lines'] : [];

        return $this->belowCostLines($this->priceLines($in, $level, $needsPin)['lines']);
    }

    public function verifyPin(string $pin, array $for): array
    {
        if ($pin === '') {
            throw new \DomainException('Needs an Admin PIN: ' . implode(', ', $for) . '.');
        }
        foreach ((new User())->all() as $u) {
            if ((int) $u['is_super'] === 1 && (int) $u['is_active'] === 1 && $u['pin_hash'] !== null && password_verify($pin, (string) $u['pin_hash'])) {
                return $u;
            }
        }
        Audit::log('pin.failed', null, null, ['for' => $for, 'by' => Auth::id()]);
        throw new \DomainException('Wrong PIN.');
    }
}
