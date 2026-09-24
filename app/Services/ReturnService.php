<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Counter;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Sale;
use App\Models\SaleReturn;

/** Returns against an invoice: restock or waste per item, debt reduction first, cash at the current rate (spec §9). */
final class ReturnService
{
    /**
     * @param list<array{sale_item_id: int, qty: string, condition: string}> $items
     * @return array{id: int, return_no: string, total_usd: string, cash: array{currency: string, amount: string}|null, debt_reduction: string}
     */
    public function create(int $saleId, array $items, string $cashCurrency, string $reason, int $sessionId, int $registerId, int $userId): array
    {
        $sales = new Sale();
        $sale = $sales->find($saleId) ?? throw new \DomainException('Sale not found.');
        if ($sale['status'] !== 'completed') {
            throw new \DomainException('A voided sale cannot be returned.');
        }
        $session = (new CashSession())->find($sessionId);
        if ($session === null || $session['status'] !== 'open') {
            throw new \DomainException('Returns need your open cash session.');
        }
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
                        'cost_usd' => $cost, 'product_id' => (int) $sold['product_id'], 'cost_per_base' => $sold['cost_per_base'], 'product_name' => $sold['product_name']];
        }
        if ($lines === []) {
            throw new \DomainException('Choose at least one item to return.');
        }
        if (!in_array($cashCurrency, ['USD', 'LBP'], true)) {
            throw new \DomainException('Choose the refund currency.');
        }
        $totalUsd = Money::fmt($total);
        $rate = (new ExchangeRate())->current();

        return Database::transaction(function () use ($sale, $saleId, $lines, $totalUsd, $cashCurrency, $reason, $sessionId, $registerId, $userId, $rate): array {
            $no = Counter::format('RTN-', Counter::next('return'));
            $returns = new SaleReturn();
            $id = $returns->create(['return_no' => $no, 'sale_id' => $saleId, 'session_id' => $sessionId, 'register_id' => $registerId, 'user_id' => $userId,
                                    'customer_id' => $sale['customer_id'], 'total_usd' => $totalUsd, 'exchange_rate' => $rate, 'reason' => trim($reason) ?: null]);
            $stock = new StockService();
            foreach ($lines as $l) {
                $returns->addItem($id, $l);
                $stock->move($l['product_id'], $l['base_qty'], 'return_restock', null, $l['cost_per_base'], 'return', $id, $userId, $sessionId);
                if ($l['item_condition'] === 'waste') {
                    $stock->move($l['product_id'], -$l['base_qty'], 'waste', 'returned-damaged', $l['cost_per_base'], 'return', $id, $userId, $sessionId);
                }
            }

            // Refund: debt first on a credit customer, the rest in cash from this session's drawer
            $debtReduction = '0.00';
            $remaining = (float) $totalUsd;
            if ($sale['customer_id'] !== null) {
                $customers = new Customer();
                $owed = (float) $customers->balance((int) $sale['customer_id']);
                if ($owed > 0.004) {
                    $debtReduction = Money::fmt(min($owed, $remaining));
                    $customers->addLedger(['customer_id' => (int) $sale['customer_id'], 'type' => 'return_credit', 'amount_usd' => '-' . $debtReduction, 'currency' => 'USD',
                                           'amount_original' => $debtReduction, 'exchange_rate' => $rate, 'return_id' => $id, 'sale_id' => $saleId, 'session_id' => $sessionId, 'user_id' => $userId, 'note' => $no]);
                    $returns->addRefund($id, 'debt_reduction', 'USD', $debtReduction, $debtReduction);
                    $remaining = round($remaining - (float) $debtReduction, 2);
                }
            }
            $cash = null;
            if ($remaining > 0.004) {
                if ($cashCurrency === 'LBP') {
                    $amount = (string) Money::roundLbp(Money::usdToLbp(Money::fmt($remaining), $rate));
                    $usd = Money::lbpToUsd((int) $amount, $rate);
                } else {
                    $amount = Money::fmt($remaining);
                    $usd = $amount;
                }
                $returns->addRefund($id, 'cash', $cashCurrency, $amount, $usd);
                (new CashSession())->addMovement(['session_id' => $sessionId, 'currency' => $cashCurrency, 'amount' => '-' . $amount, 'type' => 'refund',
                                                  'ref_type' => 'return', 'ref_id' => $id, 'user_id' => $userId, 'note' => $no]);
                $cash = ['currency' => $cashCurrency, 'amount' => $amount];
            }
            Audit::log('return.created', 'return', $id, ['no' => $no, 'invoice' => $sale['invoice_no'], 'lines' => count($lines)], (float) $totalUsd, 'USD');

            return ['id' => $id, 'return_no' => $no, 'total_usd' => $totalUsd, 'cash' => $cash, 'debt_reduction' => $debtReduction];
        });
    }
}
