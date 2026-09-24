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
 * Completing and voiding sales (spec §5–§7, §13). One transaction touches sales, sale_items,
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

        // Lines
        $units = new ProductUnit();
        $products = new Product();
        $lines = [];
        $gross = 0.0;
        $lineDiscounts = 0.0;
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
        $totalDiscount = $lineDiscounts + (float) $invoiceDiscount;
        if ($totalDiscount > 0.004) {
            $maxPct = (float) Settings::get('max_cashier_discount_pct', '0');
            $pct = $gross > 0 ? $totalDiscount / $gross * 100 : 0;
            if ($pct > $maxPct + 0.0001 && !Gate::allows('sale.discount')) {
                $needsPin[] = sprintf('discount of %.1f%%', $pct);
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

        return $result + ['change_usd' => $changeUsd, 'change_lbp' => $changeLbp, 'warnings' => $warnings, 'total_usd' => $total, 'rounding_usd' => Money::fmt($rounding)];
    }

    /** Void in the same open session only, with no returns (spec §13). */
    public function void(int $saleId, string $reason, int $userId, string $pin = ''): void
    {
        $sales = new Sale();
        $sale = $sales->find($saleId) ?? throw new \DomainException('Sale not found.');
        if ($sale['status'] !== 'completed') {
            throw new \DomainException('This sale is already voided.');
        }
        $session = (new CashSession())->find((int) $sale['session_id']);
        if ($session === null || $session['status'] !== 'open') {
            throw new \DomainException('The session is closed: make a return instead of a void.');
        }
        if ($sales->hasReturns($saleId)) {
            throw new \DomainException('This sale has returns and cannot be voided.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \DomainException('Give a reason for the void.');
        }
        $approver = Gate::allows('sale.void') ? null : $this->verifyPin($pin, ['void of ' . $sale['invoice_no']]);
        Database::transaction(function () use ($sales, $sale, $saleId, $reason, $userId, $approver): void {
            $stock = new StockService();
            foreach ($sales->items($saleId) as $item) {
                $stock->move((int) $item['product_id'], (int) $item['base_qty'], 'sale_void', $reason, $item['cost_per_base'], 'sale', $saleId, $userId, (int) $sale['session_id']);
            }
            $cash = new CashSession();
            $pdo = Database::pdo();
            $stmt = $pdo->prepare("SELECT * FROM cash_movements WHERE ref_type = 'sale' AND ref_id = :s");
            $stmt->execute(['s' => $saleId]);
            foreach ($stmt->fetchAll() as $m) {
                $cash->addMovement(['session_id' => (int) $sale['session_id'], 'currency' => $m['currency'], 'amount' => Money::fmt(-(float) $m['amount']),
                                    'type' => 'void_reversal', 'ref_type' => 'sale', 'ref_id' => $saleId, 'user_id' => $userId, 'note' => 'Void ' . $sale['invoice_no']]);
            }
            if ($sale['customer_id'] !== null) {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_usd), 0) FROM customer_ledger WHERE sale_id = :s AND type = 'sale_credit'");
                $stmt->execute(['s' => $saleId]);
                $credit = (float) $stmt->fetchColumn();
                if ($credit > 0) {
                    (new Customer())->addLedger(['customer_id' => (int) $sale['customer_id'], 'type' => 'adjustment', 'amount_usd' => Money::fmt(-$credit),
                                                 'sale_id' => $saleId, 'session_id' => (int) $sale['session_id'], 'user_id' => $userId, 'note' => 'Void ' . $sale['invoice_no']]);
                }
            }
            $sales->void($saleId, $userId, $reason);
            Audit::log('sale.voided', 'sale', $saleId, ['no' => $sale['invoice_no'], 'reason' => $reason, 'approved_by' => $approver['username'] ?? null], (float) $sale['total_usd'], 'USD');
        });
    }

    /** An Admin types their PIN on the till to approve one action. Returns the approving user. */
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
