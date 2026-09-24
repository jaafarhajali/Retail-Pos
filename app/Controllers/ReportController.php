<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\View;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\ReportService;

/** One page, several reports; each checks its own permission key (spec §16). */
final class ReportController extends Controller
{
    private const TYPES = [
        'sales' => 'report.sales', 'payments' => 'report.sales', 'profit' => 'report.profit', 'credit' => 'report.sales',
        'stock' => 'report.stock', 'purchases' => 'report.stock', 'returns' => 'report.sales', 'waste' => 'report.stock', 'sessions' => 'report.cash',
    ];

    public function index(): void
    {
        $type = $this->query('type') ?: 'sales';
        if (!isset(self::TYPES[$type])) {
            throw new HttpException(404);
        }
        if (!Gate::allows(self::TYPES[$type])) {
            throw new HttpException(403);
        }
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('from')) ? $this->query('from') : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('to')) ? $this->query('to') : date('Y-m-d');
        $by = in_array($this->query('by'), ['day', 'cashier', 'product', 'level'], true) ? $this->query('by') : 'day';
        $r = new ReportService();
        $data = match ($type) {
            'sales' => ['rows' => $r->sales($from, $to, $by)],
            'payments' => ['rows' => $r->payments($from, $to)],
            'profit' => ['profit' => $r->profit($from, $to)],
            'credit' => ['rows' => $r->credit()],
            'stock' => $this->stockData(),
            'purchases' => ['rows' => $r->purchases($from, $to)],
            'returns' => ['rows' => $r->returns($from, $to)],
            'waste' => ['rows' => $r->waste($from, $to)],
            'sessions' => ['rows' => $r->sessions($from, $to)],
        };
        $available = array_keys(array_filter(self::TYPES, static fn (string $perm): bool => Gate::allows($perm)));
        $vars = $data + ['type' => $type, 'from' => $from, 'to' => $to, 'by' => $by, 'available' => $available, 'showCost' => Gate::allows('product.view_cost')];
        if ($this->query('print') === '1') {
            View::render('reports/' . $type, $vars + ['pageTitle' => ucfirst($type) . ' report', 'printing' => true], 'layouts/print');

            return;
        }
        $this->render('reports/index', $vars, 'Reports');
    }

    private function stockData(): array
    {
        $products = (new Product())->forPrint();

        return ['rows' => $products, 'unitsById' => (new ProductUnit())->forProducts(array_map('intval', array_column($products, 'id')))];
    }
}
