<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseService;

final class PurchaseController extends Controller
{
    public function index(): void
    {
        $filters = ['supplier_id' => $this->queryInt('supplier_id'), 'from' => $this->dateQuery('from'), 'to' => $this->dateQuery('to')];
        $this->render('purchases/index', [
            'pg' => (new Purchase())->search($filters, max(1, $this->queryInt('page', 1))), 'filters' => $filters, 'suppliers' => (new Supplier())->all(),
        ], 'Purchases');
    }

    public function create(): void
    {
        $products = (new Product())->forPrint();
        $units = (new ProductUnit())->forProducts(array_map('intval', array_column($products, 'id')));
        $this->render('purchases/create', ['suppliers' => (new Supplier())->all(true), 'products' => $products, 'unitsById' => $units], 'New purchase');
    }

    public function store(): void
    {
        $lines = [];
        $rows = $_POST['lines'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r) || (int) ($r['product_id'] ?? 0) === 0) {
                    continue;
                }
                $lines[] = ['product_id' => (int) $r['product_id'], 'unit_id' => (int) ($r['unit_id'] ?? 0), 'qty' => (string) ($r['qty'] ?? ''), 'unit_cost' => (string) ($r['unit_cost'] ?? '')];
            }
        }
        try {
            $id = (new PurchaseService())->create($this->inputInt('supplier_id') ?: null, $this->input('supplier_invoice_ref'), $this->input('purchase_date'), $lines,
                $this->input('paid_now'), $this->input('paid_from'), $this->input('notes'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('purchases/create', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Purchase posted; stock and cost updated.');
        redirect('purchases/view', ['id' => $id]);
    }

    public function view(): void
    {
        $purchase = (new Purchase())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $this->render('purchases/view', ['purchase' => $purchase, 'items' => (new Purchase())->items((int) $purchase['id'])], $purchase['purchase_no']);
    }

    private function dateQuery(string $key): string
    {
        $v = $this->query($key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
