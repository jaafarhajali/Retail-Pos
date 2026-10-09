<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Counter;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleReturn;

/**
 * Returns against an invoice, or without one (2026-10-09): restock or waste per item, debt reduction first, cash at the
 * current rate (spec §9).
 */
final class ReturnService
{
    /**
     * @param list<array{sale_item_id: int, qty: string, condition: string}> $items
     * @return array{id: int, return_no: string, total_usd: string, cash: array{currency: string, amount: string}|null, debt_reduction: string}
     */
    public function create(int $saleId, array $items, string $cashCurrency, string $reason, int $sessionId, int $registerId, int $userId, bool $allowShortDrawer = false, ?string $usdPart = null, ?string $lbpPart = null): array
    {
        $sales = new Sale();
        $sale = $sales->find($saleId) ?? throw new \DomainException('Sale not found.');
        if ($sale['status'] !== 'completed') {
            throw new \DomainException('A voided sale cannot be returned.');
        }
        $this->requireOpenSession($sessionId);
        $soldItems = array_column($sales->items($saleId), null, 'id');
        $lines = [];
        $total = 0.0;
        foreach ($items as $it) {
            $sold = $soldItems[(int) ($it['sale_item_id'] ?? 0)] ?? null;
            if ($sold === null) {
                throw new \DomainException('Unknown sale line.');
            }
            $qtyText = trim((string) ($it['qty'] ?? ''));
            if ($qtyText === '' || (float) $qtyText == 0.0) {
                continue;
            }
            $qty = Quantity::parse($qtyText, (int) $sold['allows_fraction'] === 1);
            $base = Quantity::toBase($qty, (int) $sold['factor'], (int) $sold['allows_fraction'] === 1);
            $left = (int) $sold['base_qty'] - (int) $sold['returned_base_qty'];
            if ($base > $left) {
                throw new \DomainException("Only " . Quantity::unitQty($left, (int) $sold['factor']) . " {$sold['unit_name']} of {$sold['product_name']} can still be returned.");
            }
            $condition = ($it['condition'] ?? 'restock') === 'waste' ? 'waste' : 'restock';
            $refund = Money::fmt((float) $sold['line_total_usd'] * $base / (int) $sold['base_qty']);   // discount-adjusted
            $cost = Money::fmt($base * (float) $sold['cost_per_base']);
            $total += (float) $refund;
            $lines[] = ['sale_item_id' => (int) $sold['id'], 'qty' => $qty, 'base_qty' => $base, 'item_condition' => $condition, 'refund_usd' => $refund,
                        'cost_usd' => $cost, 'product_id' => (int) $sold['product_id'], 'cost_per_base' => $sold['cost_per_base'], 'product_name' => $sold['product_name'],
                        'product_unit_id' => (int) $sold['product_unit_id'], 'unit_name' => $sold['unit_name'], 'unit_price_usd' => $sold['unit_price_usd']];
        }
        if ($lines === []) {
            throw new \DomainException('Choose at least one item to return.');
        }
        if (!in_array($cashCurrency, ['USD', 'LBP', 'MIX'], true)) {
            throw new \DomainException('Choose the refund currency.');
        }
        return $this->write($sale, $sale['customer_id'] === null ? null : (int) $sale['customer_id'], $lines, Money::fmt($total), $cashCurrency, $reason, $sessionId, $registerId, $userId, $allowShortDrawer, $usdPart, $lbpPart);
    }

    /**
     * A return with no invoice (owner, 2026-10-09): the customer has one, the shop cannot find it. The items are chosen by
     * hand and refunded at today's retail price of the unit, or at the lower price the cashier types; never more.
     *
     * @param list<array{product_id: int, unit_id: int, qty: string, price: string, condition: string}> $items
     */
    public function createWithoutSale(array $items, ?int $customerId, string $reason, int $sessionId, int $registerId, int $userId, bool $allowShortDrawer = false, ?string $usdPart = null, ?string $lbpPart = null): array
    {
        $this->requireOpenSession($sessionId);
        $customer = $customerId > 0 ? ((new Customer())->find($customerId) ?? throw new \DomainException('Customer not found.')) : null;
        $units = new ProductUnit();
        $products = new Product();
        $lines = [];
        $total = 0.0;
        foreach ($items as $it) {
            $unit = $units->find((int) ($it['unit_id'] ?? 0));
            if ($unit === null || (int) $unit['product_id'] !== (int) ($it['product_id'] ?? 0)) {
                throw new \DomainException('Choose a product and one of its units on every line.');
            }
            $product = $products->find((int) $unit['product_id']) ?? throw new \DomainException('Product not found.');
            $qtyText = trim((string) ($it['qty'] ?? ''));
            if ($qtyText === '' || (float) $qtyText == 0.0) {
                continue;
            }
            $fraction = (int) $unit['allows_fraction'] === 1;
            $qty = Quantity::parse($qtyText, $fraction);
            $base = Quantity::toBase($qty, (int) $unit['factor'], $fraction);
            if ($base <= 0) {
                throw new \DomainException("Enter a quantity for {$product['name']}.");
            }
            $list = $unit['retail_price'];
            if ($list === null || (float) $list <= 0) {
                throw new \DomainException("{$product['name']} ({$unit['name']}) has no retail price, so it cannot be refunded without its invoice.");
            }
            $priceText = trim((string) ($it['price'] ?? ''));
            $price = $priceText === '' ? Money::fmt($list) : Pricing::parse($priceText);
            if ((float) $price <= 0) {
                throw new \DomainException("Enter the price to refund for {$product['name']}.");
            }
            if (Money::cmp($price, Money::fmt($list)) > 0) {
                throw new \DomainException("Without the invoice, {$product['name']} is refunded at today's price of " . usd($list) . " per {$unit['name']} at most.");
            }
            $condition = ($it['condition'] ?? 'restock') === 'waste' ? 'waste' : 'restock';
            $refund = Money::mul($price, (float) $qty);
            $total += (float) $refund;
            $lines[] = ['sale_item_id' => null, 'product_id' => (int) $product['id'], 'product_unit_id' => (int) $unit['id'], 'product_name' => $product['name'],
                        'unit_name' => $unit['name'], 'unit_price_usd' => $price, 'qty' => $qty, 'base_qty' => $base, 'item_condition' => $condition,
                        'refund_usd' => $refund, 'cost_usd' => Money::fmt($base * (float) $product['cost_per_base']), 'cost_per_base' => $product['cost_per_base']];
        }
        if ($lines === []) {
            throw new \DomainException('Choose at least one item to return.');
        }

        return $this->write(null, $customer === null ? null : (int) $customer['id'], $lines, Money::fmt($total), 'MIX', $reason, $sessionId, $registerId, $userId, $allowShortDrawer, $usdPart, $lbpPart);
    }

    private function requireOpenSession(int $sessionId): void
    {
        $session = (new CashSession())->find($sessionId);
        if ($session === null || $session['status'] !== 'open') {
            throw new \DomainException('Returns need your open cash session.');
        }
    }

    /** Writes the return, its stock, its refunds and the cash movements; $sale is null for a return without an invoice. */
    private function write(?array $sale, ?int $customerId, array $lines, string $totalUsd, string $cashCurrency, string $reason, int $sessionId, int $registerId, int $userId, bool $allowShortDrawer, ?string $usdPart, ?string $lbpPart): array
    {
        $saleId = $sale === null ? null : (int) $sale['id'];
        $rate = (new ExchangeRate())->current();

        return Database::transaction(function () use ($sale, $saleId, $customerId, $lines, $totalUsd, $cashCurrency, $reason, $sessionId, $registerId, $userId, $rate, $allowShortDrawer, $usdPart, $lbpPart): array {
            // Refund: debt first on a credit customer, the rest in cash from this session's drawer.
            // Worked out before the return is written, so its rounding is stored with it (records are never edited).
            $debtReduction = '0.00';
            $remaining = (float) $totalUsd;
            $customers = new Customer();
            if ($customerId !== null) {
                $owed = (float) $customers->balance($customerId);
                if ($owed > 0.004) {
                    $debtReduction = Money::fmt(min($owed, $remaining));
                    $remaining = round($remaining - (float) $debtReduction, 2);
                }
            }
            // The cash part, in one currency or split: USD, LBP, or "MIX" = the USD part typed by the cashier + the rest in LBP.
            $parts = [];   // each ['currency', 'amount', 'usd']
            if ($remaining > 0.004) {
                $inLbp = $remaining;
                if ($cashCurrency === 'MIX') {
                    // Two amounts, as on the expenses form: "Give back in USD" and "Give back in LBP". One alone and the rest
                    // goes in the other currency; both must add up to the cash part, give or take half a 5,000 LBP note.
                    $usdGiven = (float) (Pricing::parse((string) $usdPart, true) ?? 0);
                    $lbpGiven = CashService::parseLbp((string) ($lbpPart ?? ''), true);
                    $lbpUsd = (float) Money::lbpToUsd($lbpGiven, $rate);
                    $tolerance = (Money::step() / 2) / $rate + 0.005;
                    if ($usdGiven <= 0 && $lbpGiven <= 0) {
                        throw new \DomainException('Type how much you give back in USD, in LBP, or both: ' . usd($remaining) . ' in cash.');
                    }
                    if ($usdGiven + $lbpUsd > $remaining + $tolerance) {
                        throw new \DomainException('That is more than the ' . usd($remaining) . ' to give back in cash (' . usd($usdGiven + $lbpUsd) . ').');
                    }
                    if ($usdGiven > 0 && $lbpGiven > 0 && $usdGiven + $lbpUsd < $remaining - $tolerance) {
                        throw new \DomainException('USD + LBP must add up to the ' . usd($remaining) . ' to give back in cash (now ' . usd($usdGiven + $lbpUsd) . ').');
                    }
                    if ($usdGiven > 0) {
                        $parts[] = ['currency' => 'USD', 'amount' => Money::fmt($usdGiven), 'usd' => Money::fmt($usdGiven)];
                    }
                    if ($lbpGiven > 0) {
                        $parts[] = ['currency' => 'LBP', 'amount' => (string) $lbpGiven, 'usd' => Money::fmt($lbpUsd)];
                        // LBP typed alone: the rest in USD, unless it is only what the 5,000 rounding leaves (then it is rounding)
                        $rest = round($remaining - $usdGiven - $lbpUsd, 2);
                        if ($usdGiven <= 0 && $rest > $tolerance) {
                            array_unshift($parts, ['currency' => 'USD', 'amount' => Money::fmt($rest), 'usd' => Money::fmt($rest)]);
                        }
                        $inLbp = 0.0;
                    } else {
                        $inLbp = round($remaining - $usdGiven, 2);   // USD typed alone: the rest in LBP, rounded to 5,000 below
                    }
                } elseif ($cashCurrency === 'USD') {
                    $parts[] = ['currency' => 'USD', 'amount' => Money::fmt($remaining), 'usd' => Money::fmt($remaining)];
                    $inLbp = 0.0;
                }
                if ($inLbp > 0.004) {
                    // LBP is handed back in 5,000 notes, like change: 651,600 → 650,000
                    $lbp = Money::roundLbp(Money::usdToLbp(Money::fmt($inLbp), $rate));
                    if ($lbp > 0) {
                        $parts[] = ['currency' => 'LBP', 'amount' => (string) $lbp, 'usd' => Money::lbpToUsd($lbp, $rate)];
                    }
                }
            }
            $cashUsd = array_sum(array_map(static fn (array $p): float => (float) $p['usd'], $parts));
            // What the rounding kept: +0.02 when $7.24 was refunded as 650,000 LBP ($7.22). Same sign as sales.rounding_usd.
            $rounding = Money::fmt((float) $totalUsd - (float) $debtReduction - $cashUsd);
            // The refund must come out of a drawer that has it, in each currency (I5, warn and allow).
            $out = [];
            foreach ($parts as $p) {
                $out[$p['currency']] = $p['amount'];
            }
            $short = $parts === [] ? null : CashService::drawerShortfall($sessionId, $out, [], 'refund');
            if ($short !== null && !$allowShortDrawer) {
                throw new \DomainException($short, CashService::SHORT_DRAWER);
            }

            $no = Counter::format('RTN-', Counter::next('return'));
            $returns = new SaleReturn();
            $id = $returns->create(['return_no' => $no, 'sale_id' => $saleId, 'session_id' => $sessionId, 'register_id' => $registerId, 'user_id' => $userId,
                                    'customer_id' => $customerId, 'total_usd' => $totalUsd, 'rounding_usd' => $rounding, 'exchange_rate' => $rate,
                                    'reason' => trim($reason) ?: null]);
            $stock = new StockService();
            foreach ($lines as $l) {
                $returns->addItem($id, $l);
                $stock->move($l['product_id'], $l['base_qty'], 'return_restock', null, $l['cost_per_base'], 'return', $id, $userId, $sessionId);
                if ($l['item_condition'] === 'waste') {
                    $stock->move($l['product_id'], -$l['base_qty'], 'waste', 'returned-damaged', $l['cost_per_base'], 'return', $id, $userId, $sessionId);
                }
            }

            if ((float) $debtReduction > 0.004) {
                $customers->addLedger(['customer_id' => $customerId, 'type' => 'return_credit', 'amount_usd' => '-' . $debtReduction, 'currency' => 'USD',
                                       'amount_original' => $debtReduction, 'exchange_rate' => $rate, 'return_id' => $id, 'sale_id' => $saleId, 'session_id' => $sessionId, 'user_id' => $userId, 'note' => $no]);
                $returns->addRefund($id, 'debt_reduction', 'USD', $debtReduction, $debtReduction);
            }
            foreach ($parts as $p) {   // one refund line and one cash movement per currency
                $returns->addRefund($id, 'cash', $p['currency'], $p['amount'], $p['usd']);
                (new CashSession())->addMovement(['session_id' => $sessionId, 'currency' => $p['currency'], 'amount' => '-' . $p['amount'], 'type' => 'refund',
                                                  'ref_type' => 'return', 'ref_id' => $id, 'user_id' => $userId, 'note' => $no]);
            }
            $cashParts = array_map(static fn (array $p): array => ['currency' => $p['currency'], 'amount' => $p['amount']], $parts);
            $cash = count($cashParts) === 1 ? $cashParts[0] : ($cashParts === [] ? null : ['currency' => 'USD+LBP', 'amount' => null]);
            Audit::log('return.created', 'return', $id, ['no' => $no, 'invoice' => $sale['invoice_no'] ?? null, 'without_invoice' => $sale === null, 'lines' => count($lines), 'rounding_usd' => $rounding], (float) $totalUsd, 'USD');
            if ($short !== null) {   // confirmed: the owner sees who refunded money the drawer did not hold
                Audit::log('drawer.short', 'return', $id, ['no' => $no, 'warning' => $short]);
            }

            return ['id' => $id, 'return_no' => $no, 'total_usd' => $totalUsd, 'rounding_usd' => $rounding, 'cash' => $cash, 'cash_parts' => $cashParts, 'debt_reduction' => $debtReduction];
        });
    }
}
