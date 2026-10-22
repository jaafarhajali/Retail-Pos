<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Customer;

/**
 * Debts follow the price (owner, 2026-10-09 for floating products; 2026-10-22 for every product): a customer who took
 * goods on credit pays the higher of the price of the day he took them and the price of the day he pays. The
 * difference is charged to his ledger as a "price adjustment" the moment he pays, once per sale line, and only on
 * the part of an invoice he has not paid yet (10 boxes at $10, $50 paid, the box now $12: 5 boxes × $2 = +$10).
 * A price that went down changes nothing: the shop never loses on credit.
 */
final class DebtService
{
    /**
     * What would be charged today: lines of the customer's unpaid invoices whose price rose since the sale, each for
     * the share of the invoice still unpaid.
     *
     * @return array{amount: string, lines: list<array{sale_id: int, invoice_no: string, sale_item_id: int, product_name: string,
     *               unit_name: string, qty: string, price_old: string, price_new: string, amount: string}>}
     */
    public function pending(int $customerId): array
    {
        $unpaid = $this->unpaidInvoices($customerId);
        if ($unpaid === []) {
            return ['amount' => '0.00', 'lines' => []];
        }
        $in = implode(',', array_keys($unpaid));
        $rows = Database::pdo()->query(
            "SELECT i.id, i.sale_id, s.invoice_no, i.product_name, i.unit_name, i.unit_price_usd, i.line_total_usd, i.base_qty, pu.factor,
                    CASE WHEN s.price_level = 'wholesale' THEN pu.wholesale_price ELSE pu.retail_price END AS today,
                    COALESCE((SELECT SUM(ri.base_qty) FROM return_items ri WHERE ri.sale_item_id = i.id), 0) AS returned,
                    EXISTS (SELECT 1 FROM debt_adjustments d WHERE d.sale_item_id = i.id) AS adjusted
             FROM sale_items i JOIN sales s ON s.id = i.sale_id JOIN product_units pu ON pu.id = i.product_unit_id
             WHERE i.sale_id IN ($in)
             ORDER BY i.id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        // What is left of each invoice after returns, at the prices paid: the unpaid share is measured against it,
        // so goods returned are not counted twice (their return credit already paid part of the debt).
        $worth = [];
        foreach ($rows as $r) {
            $base = (int) $r['base_qty'];
            $left = $base - (int) $r['returned'];
            if ($base > 0 && $left > 0) {
                $worth[(int) $r['sale_id']] = ($worth[(int) $r['sale_id']] ?? 0.0) + (float) $r['line_total_usd'] * $left / $base;
            }
        }
        $lines = [];
        $total = 0.0;
        foreach ($rows as $r) {
            $saleId = (int) $r['sale_id'];
            $left = (int) $r['base_qty'] - (int) $r['returned'];
            $old = (float) $r['unit_price_usd'];
            $today = $r['today'] === null ? 0.0 : (float) $r['today'];
            if ((int) $r['adjusted'] === 1 || $left <= 0 || $today <= $old + 0.004 || ($worth[$saleId] ?? 0.0) <= 0.004) {
                continue;
            }
            $share = min(1.0, $unpaid[$saleId] / $worth[$saleId]);   // the part of this invoice not paid yet
            $qty = rtrim(rtrim(number_format($left / (int) $r['factor'] * $share, 3, '.', ''), '0'), '.');
            $amount = Money::fmt((float) $qty * ($today - $old));
            if ((float) $amount <= 0.004) {
                continue;
            }
            $total += (float) $amount;
            $lines[] = ['sale_id' => (int) $r['sale_id'], 'invoice_no' => $r['invoice_no'], 'sale_item_id' => (int) $r['id'], 'product_name' => $r['product_name'],
                        'unit_name' => $r['unit_name'], 'qty' => $qty, 'price_old' => Money::fmt($old), 'price_new' => Money::fmt($today), 'amount' => $amount];
        }

        return ['amount' => Money::fmt($total), 'lines' => $lines];
    }

    /** One line per pending adjustment, for a receipt or a dialog: "3 Box Al Fakher $32.00 → $35.00 (+$9.00)". */
    public function describe(array $pending): string
    {
        return implode('; ', array_map(static fn (array $l): string => rtrim(rtrim($l['qty'], '0'), '.') . ' ' . $l['unit_name'] . ' ' . $l['product_name']
            . ' ' . usd($l['price_old']) . ' → ' . usd($l['price_new']) . ' (+' . usd($l['amount']) . ')', $pending['lines']));
    }

    /** Charges what is pending to the customer, one ledger line per invoice, and remembers each re-priced sale line. Returns the total. */
    public function charge(int $customerId, int $userId, ?int $sessionId): string
    {
        $pending = $this->pending($customerId);
        if ($pending['lines'] === []) {
            return '0.00';
        }
        $customers = new Customer();
        $pdo = Database::pdo();
        $bySale = [];
        foreach ($pending['lines'] as $l) {
            $bySale[$l['sale_id']][] = $l;
        }
        foreach ($bySale as $saleId => $lines) {
            $amount = Money::fmt(array_sum(array_map(static fn (array $l): float => (float) $l['amount'], $lines)));
            $ledgerId = $customers->addLedger(['customer_id' => $customerId, 'type' => 'price_adjustment', 'amount_usd' => $amount, 'currency' => 'USD',
                                               'amount_original' => $amount, 'sale_id' => $saleId, 'session_id' => $sessionId, 'user_id' => $userId,
                                               'note' => 'Price of the day on ' . $lines[0]['invoice_no'] . ': ' . $this->describe(['lines' => $lines])]);
            $st = $pdo->prepare('INSERT INTO debt_adjustments (customer_id, sale_id, sale_item_id, qty, price_old, price_new, amount_usd, ledger_id, user_id)
                                 VALUES (:c, :s, :i, :q, :po, :pn, :a, :l, :u)');
            foreach ($lines as $l) {
                $st->execute(['c' => $customerId, 's' => $saleId, 'i' => $l['sale_item_id'], 'q' => $l['qty'], 'po' => $l['price_old'], 'pn' => $l['price_new'],
                              'a' => $l['amount'], 'l' => $ledgerId, 'u' => $userId]);
            }
        }
        Audit::log('debt.repriced', 'customer', $customerId, ['lines' => count($pending['lines']), 'detail' => $this->describe($pending)], (float) $pending['amount'], 'USD');

        return $pending['amount'];
    }

    /**
     * What is still unpaid of each of the customer's credit invoices. Payments, return credits and reductions are a
     * pool that covers what he owes oldest first; the part of an invoice's credit the pool does not reach is unpaid.
     * Only the invoice's own credit counts (a price adjustment already charged belongs to lines already re-priced).
     *
     * @return array<int, float> sale id => unpaid USD
     */
    private function unpaidInvoices(int $customerId): array
    {
        $st = Database::pdo()->prepare('SELECT amount_usd, sale_id, type FROM customer_ledger WHERE customer_id = :c ORDER BY id');
        $st->execute(['c' => $customerId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $pool = 0.0;
        foreach ($rows as $r) {
            if ((float) $r['amount_usd'] < 0) {
                $pool += -(float) $r['amount_usd'];
            }
        }
        $unpaid = [];
        foreach ($rows as $r) {
            $a = (float) $r['amount_usd'];
            if ($a <= 0) {
                continue;
            }
            $covered = min($pool, $a);
            $pool -= $covered;
            $rest = $a - $covered;
            if ($rest > 0.004 && $r['type'] === 'sale_credit' && $r['sale_id'] !== null) {
                $unpaid[(int) $r['sale_id']] = ($unpaid[(int) $r['sale_id']] ?? 0.0) + $rest;
            }
        }

        return $unpaid;
    }
}
