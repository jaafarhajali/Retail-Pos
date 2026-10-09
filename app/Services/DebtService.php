<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Customer;

/**
 * Debts on floating-price products (owner, 2026-10-09): a customer who took them on credit pays the price of the day
 * he pays, when it is higher than the price of the day he took them. The difference is charged to his ledger as a
 * "price adjustment" the moment he pays, once per sale line, and only on invoices he has not settled yet.
 * A price that went down changes nothing: the shop never loses on credit.
 */
final class DebtService
{
    /**
     * What would be charged today: floating lines of the customer's open invoices whose price rose since the sale.
     *
     * @return array{amount: string, lines: list<array{sale_id: int, invoice_no: string, sale_item_id: int, product_name: string,
     *               unit_name: string, qty: string, price_old: string, price_new: string, amount: string}>}
     */
    public function pending(int $customerId): array
    {
        $open = $this->openInvoices($customerId);
        if ($open === []) {
            return ['amount' => '0.00', 'lines' => []];
        }
        $in = implode(',', $open);
        $rows = Database::pdo()->query(
            "SELECT i.id, i.sale_id, s.invoice_no, i.product_name, i.unit_name, i.unit_price_usd, i.base_qty, pu.factor,
                    CASE WHEN s.price_level = 'wholesale' THEN pu.wholesale_price ELSE pu.retail_price END AS today,
                    COALESCE((SELECT SUM(ri.base_qty) FROM return_items ri WHERE ri.sale_item_id = i.id), 0) AS returned
             FROM sale_items i JOIN sales s ON s.id = i.sale_id JOIN products p ON p.id = i.product_id JOIN product_units pu ON pu.id = i.product_unit_id
             WHERE i.sale_id IN ($in) AND p.price_floats = 1 AND NOT EXISTS (SELECT 1 FROM debt_adjustments d WHERE d.sale_item_id = i.id)
             ORDER BY i.id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $lines = [];
        $total = 0.0;
        foreach ($rows as $r) {
            $left = (int) $r['base_qty'] - (int) $r['returned'];
            $old = (float) $r['unit_price_usd'];
            $today = $r['today'] === null ? 0.0 : (float) $r['today'];
            if ($left <= 0 || $today <= $old + 0.004) {
                continue;
            }
            $qty = Quantity::unitQty($left, (int) $r['factor']);
            $amount = Money::fmt((float) $qty * ($today - $old));
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
     * The customer's invoices that are not fully paid yet. Payments, return credits and reductions are a pool that
     * covers what he owes oldest first; an invoice the pool does not fully cover is open.
     *
     * @return list<int> sale ids
     */
    private function openInvoices(int $customerId): array
    {
        $st = Database::pdo()->prepare('SELECT amount_usd, sale_id FROM customer_ledger WHERE customer_id = :c ORDER BY id');
        $st->execute(['c' => $customerId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $pool = 0.0;
        foreach ($rows as $r) {
            if ((float) $r['amount_usd'] < 0) {
                $pool += -(float) $r['amount_usd'];
            }
        }
        $open = [];
        foreach ($rows as $r) {
            $a = (float) $r['amount_usd'];
            if ($a <= 0) {
                continue;
            }
            if ($pool >= $a - 0.004) {
                $pool -= $a;
                continue;
            }
            $pool = 0.0;
            if ($r['sale_id'] !== null) {
                $open[(int) $r['sale_id']] = true;
            }
        }

        return array_keys($open);
    }
}
