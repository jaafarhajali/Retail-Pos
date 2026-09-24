<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\StockMovement;
use PDO;

/** Read-only report queries (spec §16). Dates are Y-m-d, inclusive. */
final class ReportService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function range(string $from, string $to): array
    {
        return ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
    }

    /** @param string $by day|cashier|product|level */
    public function sales(string $from, string $to, string $by): array
    {
        $p = $this->range($from, $to);
        return match ($by) {
            'cashier' => $this->rows("SELECT u.username AS label, COUNT(*) AS invoices, SUM(s.total_usd) AS total, SUM(s.cost_total_usd) AS cost FROM sales s JOIN users u ON u.id = s.user_id
                                      WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t GROUP BY u.id ORDER BY total DESC", $p),
            'product' => $this->rows("SELECT i.product_name AS label, i.unit_name, SUM(i.qty) AS qty, SUM(i.line_total_usd) AS total, SUM(i.line_cost_usd) AS cost FROM sale_items i JOIN sales s ON s.id = i.sale_id
                                      WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t GROUP BY i.product_id, i.product_unit_id ORDER BY total DESC", $p),
            'level' => $this->rows("SELECT price_level AS label, COUNT(*) AS invoices, SUM(total_usd) AS total, SUM(cost_total_usd) AS cost FROM sales
                                    WHERE status = 'completed' AND created_at BETWEEN :f AND :t GROUP BY price_level", $p),
            default => $this->rows("SELECT DATE(created_at) AS label, COUNT(*) AS invoices, SUM(total_usd) AS total, SUM(cost_total_usd) AS cost, SUM(discount_usd) AS discount FROM sales
                                    WHERE status = 'completed' AND created_at BETWEEN :f AND :t GROUP BY DATE(created_at) ORDER BY label", $p),
        };
    }

    public function payments(string $from, string $to): array
    {
        return $this->rows("SELECT p.method, p.currency, COUNT(*) AS n, SUM(p.amount) AS amount, SUM(p.amount_usd) AS amount_usd FROM sale_payments p JOIN sales s ON s.id = p.sale_id
                            WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t GROUP BY p.method, p.currency ORDER BY p.method, p.currency", $this->range($from, $to));
    }

    public function profit(string $from, string $to): array
    {
        $p = $this->range($from, $to);
        $s = $this->rows("SELECT COALESCE(SUM(total_usd), 0) AS gross, COALESCE(SUM(rounding_usd), 0) AS rounding, COALESCE(SUM(cost_total_usd), 0) AS cogs, COUNT(*) AS invoices
                          FROM sales WHERE status = 'completed' AND created_at BETWEEN :f AND :t", $p)[0];
        $r = $this->rows("SELECT COALESCE(SUM(r.total_usd), 0) AS refunds, COALESCE(SUM(ri.cost_usd), 0) AS cost FROM returns r LEFT JOIN return_items ri ON ri.return_id = r.id
                          WHERE r.created_at BETWEEN :f AND :t", $p)[0];
        $waste = (new StockMovement())->wasteCost($from, $to);
        $expenses = (new Expense())->totalUsd($from, $to);
        $net = (float) $s['gross'] - (float) $r['refunds'] + (float) $s['rounding'];
        $cogs = (float) $s['cogs'] - (float) $r['cost'];
        $grossProfit = $net - $cogs - (float) $waste;

        return [
            'invoices' => (int) $s['invoices'], 'gross_sales' => Money::fmt($s['gross']), 'returns' => Money::fmt($r['refunds']), 'rounding' => Money::fmt($s['rounding']),
            'net_sales' => Money::fmt($net), 'cogs' => Money::fmt($cogs), 'waste' => $waste, 'gross_profit' => Money::fmt($grossProfit),
            'expenses' => $expenses, 'net_profit' => Money::fmt($grossProfit - (float) $expenses),
        ];
    }

    public function credit(): array
    {
        return (new Customer())->debtors();
    }

    public function returns(string $from, string $to): array
    {
        return $this->rows("SELECT r.*, s.invoice_no, u.username, c.name AS customer_name FROM returns r JOIN sales s ON s.id = r.sale_id JOIN users u ON u.id = r.user_id
                            LEFT JOIN customers c ON c.id = r.customer_id WHERE r.created_at BETWEEN :f AND :t ORDER BY r.id DESC", $this->range($from, $to));
    }

    public function waste(string $from, string $to): array
    {
        return $this->rows("SELECT m.*, p.name AS product_name, p.base_unit, u.username FROM stock_movements m JOIN products p ON p.id = m.product_id LEFT JOIN users u ON u.id = m.user_id
                            WHERE m.type = 'waste' AND m.created_at BETWEEN :f AND :t ORDER BY m.id DESC", $this->range($from, $to));
    }

    public function purchases(string $from, string $to): array
    {
        return $this->rows("SELECT p.*, s.name AS supplier_name FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.purchase_date BETWEEN :f AND :t ORDER BY p.id DESC",
            ['f' => $from, 't' => $to]);
    }

    public function sessions(string $from, string $to): array
    {
        return $this->rows("SELECT s.*, r.name AS register_name, u.username FROM cash_sessions s JOIN registers r ON r.id = s.register_id JOIN users u ON u.id = s.user_id
                            WHERE s.opened_at BETWEEN :f AND :t ORDER BY s.id DESC", $this->range($from, $to));
    }

    /** Today's numbers for the dashboard. */
    public function today(): array
    {
        $d = date('Y-m-d');
        $s = $this->rows("SELECT COUNT(*) AS invoices, COALESCE(SUM(total_usd), 0) AS total FROM sales WHERE status = 'completed' AND created_at BETWEEN :f AND :t", $this->range($d, $d))[0];
        $low = $this->rows('SELECT COUNT(*) FROM products WHERE is_active = 1 AND min_stock_base IS NOT NULL AND stock_base <= min_stock_base', [])[0];
        $open = $this->rows("SELECT COUNT(*) FROM cash_sessions WHERE status = 'open'", [])[0];

        return ['invoices' => (int) $s['invoices'], 'total' => Money::fmt($s['total']), 'low_stock' => (int) reset($low), 'open_sessions' => (int) reset($open)];
    }
}
