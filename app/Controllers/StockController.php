<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Gate;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Services\StockService;

final class StockController extends Controller
{
    public function index(): void
    {
        $type = $this->query('type');
        $filters = [
            'product_id' => $this->queryInt('product_id'),
            'type' => in_array($type, ['opening', 'purchase', 'sale', 'sale_void', 'return_restock', 'waste', 'adjustment', 'stocktake'], true) ? $type : '',
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('from')) ? $this->query('from') : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('to')) ? $this->query('to') : '',
        ];
        $this->render('stock/index', [
            'pg' => (new StockMovement())->search($filters, max(1, $this->queryInt('page', 1))), 'filters' => $filters,
            'products' => (new Product())->forPrint(), 'showCost' => Gate::allows('product.view_cost'), 'canAdjust' => Gate::allows('stock.adjust'),
        ], 'Stock movements');
    }

    public function adjustForm(): void
    {
        $products = (new Product())->forPrint();
        $this->render('stock/adjust', [
            'products' => $products, 'unitsById' => (new ProductUnit())->forProducts(array_map('intval', array_column($products, 'id'))),
            'reasons' => StockService::REASONS, 'productId' => $this->queryInt('product_id'), 'showCost' => Gate::allows('product.view_cost'),
        ], 'Stock adjustment');
    }

    public function adjust(): void
    {
        try {
            (new StockService())->adjust($this->inputInt('product_id'), $this->inputInt('unit_id'), $this->input('qty'), $this->input('type'), $this->input('reason'),
                Gate::allows('product.view_cost') ? $this->input('unit_cost') : null, Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('stock/adjust', ['product_id' => $this->inputInt('product_id')], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Stock updated.');
        redirect('stock', ['product_id' => $this->inputInt('product_id')]);
    }
}
