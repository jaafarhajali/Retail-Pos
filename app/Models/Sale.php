<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Sales, their lines and payments (spec §3.7). Append-only except the void status. */
final class Sale extends Model
{
    private const SELECT = 'SELECT s.*, u.username, r.name AS register_name, c.name AS customer_name, cs.session_no FROM sales s
                            JOIN users u ON u.id = s.user_id JOIN registers r ON r.id = s.register_id
                            LEFT JOIN customers c ON c.id = s.customer_id JOIN cash_sessions cs ON cs.id = s.session_id';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE s.id = :id', ['id' => $id]);
    }

    public function findByInvoice(string $no): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE s.invoice_no = :n', ['n' => $no]);
    }

    public function items(int $saleId): array
    {
        return $this->fetchAll(
            'SELECT i.*, COALESCE((SELECT SUM(ri.base_qty) FROM return_items ri WHERE ri.sale_item_id = i.id), 0) AS returned_base_qty,
                    pu.factor, pu.allows_fraction FROM sale_items i JOIN product_units pu ON pu.id = i.product_unit_id WHERE i.sale_id = :s ORDER BY i.id',
            ['s' => $saleId]
        );
    }

    public function payments(int $saleId): array
    {
        return $this->fetchAll('SELECT * FROM sale_payments WHERE sale_id = :s ORDER BY id', ['s' => $saleId]);
    }

    public function search(array $filters, int $page): array
    {
        $where = ['1=1'];
        $params = [];
        if ($filters['q'] !== '') {
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $where[] = '(s.invoice_no LIKE :q1 OR c.name LIKE :q2)';
            $params += ['q1' => $like, 'q2' => $like];
        }
        if ($filters['session_id'] > 0) {
            $where[] = 's.session_id = :ses';
            $params['ses'] = $filters['session_id'];
        }
        if ($filters['user_id'] > 0) {
            $where[] = 's.user_id = :u';
            $params['u'] = $filters['user_id'];
        }
        if ($filters['customer_id'] > 0) {
            $where[] = 's.customer_id = :c';
            $params['c'] = $filters['customer_id'];
        }
        if ($filters['from'] !== '') {
            $where[] = 's.created_at >= :f';
            $params['f'] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            $where[] = 's.created_at <= :t';
            $params['t'] = $filters['to'] . ' 23:59:59';
        }
        $w = implode(' AND ', $where);

        return $this->paginate(
            self::SELECT . " WHERE {$w} ORDER BY s.id DESC",
            "SELECT COUNT(*) FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE {$w}",
            $params, $page, 25
        );
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO sales (invoice_no, register_id, session_id, user_id, customer_id, price_level, subtotal_usd, discount_usd, total_usd, rounding_usd,
                                cost_total_usd, exchange_rate, change_usd, change_lbp, notes)
             VALUES (:no, :r, :s, :u, :c, :pl, :sub, :d, :t, :ro, :ct, :x, :cu, :cl, :n)',
            [
                'no' => $f['invoice_no'], 'r' => $f['register_id'], 's' => $f['session_id'], 'u' => $f['user_id'], 'c' => $f['customer_id'],
                'pl' => $f['price_level'], 'sub' => $f['subtotal_usd'], 'd' => $f['discount_usd'], 't' => $f['total_usd'], 'ro' => $f['rounding_usd'],
                'ct' => $f['cost_total_usd'], 'x' => $f['exchange_rate'], 'cu' => $f['change_usd'], 'cl' => $f['change_lbp'], 'n' => $f['notes'],
            ]
        );

        return $this->lastId();
    }

    public function addItem(int $saleId, array $l): int
    {
        $this->execute(
            'INSERT INTO sale_items (sale_id, product_id, product_unit_id, product_name, unit_name, qty, base_qty, unit_price_usd, line_discount_usd,
                                     line_total_usd, cost_per_base, line_cost_usd, entry_mode, price_overridden)
             VALUES (:s, :p, :u, :pn, :un, :q, :b, :pr, :ld, :lt, :cb, :lc, :em, :po)',
            [
                's' => $saleId, 'p' => $l['product_id'], 'u' => $l['product_unit_id'], 'pn' => $l['product_name'], 'un' => $l['unit_name'],
                'q' => $l['qty'], 'b' => $l['base_qty'], 'pr' => $l['unit_price_usd'], 'ld' => $l['line_discount_usd'], 'lt' => $l['line_total_usd'],
                'cb' => $l['cost_per_base'], 'lc' => $l['line_cost_usd'], 'em' => $l['entry_mode'], 'po' => (int) $l['price_overridden'],
            ]
        );

        return $this->lastId();
    }

    public function addPayment(int $saleId, string $method, string $currency, string $amount, string $amountUsd): void
    {
        $this->execute('INSERT INTO sale_payments (sale_id, method, currency, amount, amount_usd) VALUES (:s, :m, :c, :a, :u)',
            ['s' => $saleId, 'm' => $method, 'c' => $currency, 'a' => $amount, 'u' => $amountUsd]);
    }

    public function void(int $id, int $userId, string $reason): void
    {
        $this->execute("UPDATE sales SET status = 'voided', void_reason = :r, voided_by = :u, voided_at = NOW() WHERE id = :id", ['r' => $reason, 'u' => $userId, 'id' => $id]);
    }

    public function hasReturns(int $saleId): bool
    {
        return (bool) $this->fetchValue('SELECT COUNT(*) FROM returns WHERE sale_id = :s', ['s' => $saleId]);
    }

    /** Session totals for X/Z: sales, retail/wholesale, payments by method+currency, counts. */
    public function sessionSummary(int $sessionId): array
    {
        $head = $this->fetch(
            "SELECT COUNT(*) AS invoices, COALESCE(SUM(total_usd), 0) AS total, COALESCE(SUM(rounding_usd), 0) AS rounding,
                    COALESCE(SUM(CASE WHEN price_level = 'retail' THEN total_usd ELSE 0 END), 0) AS retail,
                    COALESCE(SUM(CASE WHEN price_level = 'wholesale' THEN total_usd ELSE 0 END), 0) AS wholesale,
                    COALESCE(SUM(change_usd), 0) AS change_usd, COALESCE(SUM(change_lbp), 0) AS change_lbp
             FROM sales WHERE session_id = :s1 AND status = 'completed'",
            ['s1' => $sessionId]
        ) ?? [];
        $head['voided'] = (int) $this->fetchValue("SELECT COUNT(*) FROM sales WHERE session_id = :s AND status = 'voided'", ['s' => $sessionId]);
        $head['payments'] = $this->fetchAll(
            "SELECT p.method, p.currency, SUM(p.amount) AS amount, SUM(p.amount_usd) AS amount_usd FROM sale_payments p
             JOIN sales s ON s.id = p.sale_id WHERE s.session_id = :s AND s.status = 'completed' GROUP BY p.method, p.currency ORDER BY p.method, p.currency",
            ['s' => $sessionId]
        );

        return $head;
    }
}
