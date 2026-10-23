<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\View;
use App\Models\Barcode;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\Pricing;
use App\Services\ProductImageService;
use App\Services\ProductService;

final class ProductController extends Controller
{
    public function index(): void
    {
        $filters = $this->filters();
        $pg = (new Product())->search($filters, max(1, $this->queryInt('page', 1)));
        $this->render('products/index', [
            'pg'         => $pg,
            'filters'    => $filters,
            'unitsById'  => (new ProductUnit())->forProducts(array_map('intval', array_column($pg['rows'], 'id'))),
            'categories' => (new Category())->all(),
            'showCost'   => Gate::allows('product.view_cost'),
            'canManage'  => Gate::allows('product.manage'),
            'usedIds'    => (new Product())->usedIds(array_column($pg['rows'], 'id')),
        ], 'Products');
    }

    /** Printable A4 table, grouped by category: what the list shows with the same filters, all pages of it. */
    public function print(): void
    {
        $products = (new Product())->filtered($this->filters());
        $groups = [];
        foreach ($products as $p) {
            $groups[$p['category_name'] ?? 'Other'][] = $p;
        }
        View::render('products/print', [
            'pageTitle' => 'Products and stock',
            'products'  => $products,
            'groups'    => $groups,
            'unitsById' => (new ProductUnit())->forProducts(array_map('intval', array_column($products, 'id'))),
            'showCost'  => Gate::allows('product.view_cost'),
        ], 'layouts/print');
    }

    public function create(): void
    {
        $this->render('products/form', $this->formData(null), 'Add product');
    }

    public function edit(): void
    {
        $product = (new Product())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $this->render('products/form', $this->formData($product), $product['name']);
    }

    /** The whole product page in one request (ProductService::save). The photo is stored once the rest is safe. */
    public function save(): void
    {
        $id = $this->inputInt('product_id');
        try {
            $saved = (new ProductService())->save($id, $this->productInput() + [
                'is_active' => $id === 0 || isset($_POST['is_active']), 'allow_same_name' => isset($_POST['allow_same_name']),
                'units' => is_array($_POST['units'] ?? null) ? $_POST['units'] : [], 'main_unit' => $this->input('main_unit'),
                'cost' => $this->input('cost'), 'cost_unit' => $this->input('cost_unit'),
                'min_qty' => $this->input('min_qty'), 'min_unit' => $this->input('min_unit'),
                'opening_qty' => $this->input('opening_qty'), 'opening_unit' => $this->input('opening_unit'),
            ], \App\Core\Auth::id());
        } catch (\DomainException $e) {
            $this->failBack($id > 0 ? 'products/edit' : 'products/create', $id > 0 ? ['id' => $id] : [],
                [$e->getCode() === ProductService::SAME_NAME ? 'same_name' : 'form' => $e->getMessage()]);
        }

        $photo = '';
        try {
            $file = $_FILES['image'] ?? null;
            if (is_array($file) && is_int($file['error'] ?? null) && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                    throw new \DomainException('The image must be 5 MB or smaller.');
                }
                if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                    throw new \DomainException('The upload failed.');
                }
                (new ProductImageService())->store($saved, (string) $file['tmp_name'], (int) $file['size']);
            } elseif (isset($_POST['remove_image'])) {
                (new ProductImageService())->remove($saved);
            }
        } catch (\DomainException $e) {
            $photo = ' The photo was not saved: ' . $e->getMessage();
        }

        $name = (string) ((new Product())->find($saved)['name'] ?? 'The product');
        Flash::set($photo === '' ? 'success' : 'warning', $name . ($id === 0 ? ' was added.' : ' was saved.') . $photo);
        if ($this->input('then') === 'add') {
            redirect('products/create');
        }
        redirect('products/edit', ['id' => $saved]);
    }

    /** Only a product that was never sold, purchased, counted or moved; any other is deactivated on its page. */
    public function delete(): void
    {
        $id = $this->inputInt('product_id');
        try {
            $name = (string) ((new Product())->find($id)['name'] ?? '');
            (new ProductService())->delete($id);
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', $name . ' was deleted.');
        redirect('products');
    }
    /** @return array<string, mixed> */
    private function productInput(): array
    {
        return [
            'name'                 => $this->input('name'),
            'category_id'          => $this->input('category_id'),
            'base_unit'            => $this->input('base_unit'),
            'internal_code'        => $this->input('internal_code'),
            'description'          => $this->input('description'),
            'target_margin_pct'    => $this->input('target_margin_pct'),
            'show_on_pos_grid'     => isset($_POST['show_on_pos_grid']),
            'allow_price_override' => isset($_POST['allow_price_override']),
            'price_floats'         => isset($_POST['price_floats']),
        ];
    }

    /** @return array<string, mixed> */
    private function formData(?array $product): array
    {
        $id = $product === null ? 0 : (int) $product['id'];
        $categoryModel = new Category();
        $categories = $categoryModel->all(true);
        // A product may sit in a deactivated category (the recommended alternative to deleting one):
        // keep offering it, or the next save would silently move the product to "none".
        if ($product !== null && $product['category_id'] !== null
            && !in_array((int) $product['category_id'], array_map('intval', array_column($categories, 'id')), true)) {
            $current = $categoryModel->find((int) $product['category_id']);
            if ($current !== null) {
                $categories[] = $current;
            }
        }

        $units = $id > 0 ? (new ProductUnit())->forProduct($id) : [];
        $usedUnits = [];
        foreach ($units as $u) {
            $usedUnits[(int) $u['id']] = (new ProductUnit())->isUsed((int) $u['id']);
        }

        return [
            'product'    => $product,
            'categories' => $categories,
            'units'      => $units,
            'usedUnits'  => $usedUnits,
            // With stock history the sizes are locked and stock changes go through the stock pages.
            'locked'     => $id > 0 && (new \App\Models\StockMovement())->hasMovements($id),
            'used'       => $id > 0 && (new Product())->isUsed($id),
            'stockText'  => $product === null ? '' : \App\Services\Quantity::format((int) $product['stock_base'], $units, (string) $product['base_unit']),
            'canStock'   => Gate::allows('stock.adjust'),
            'barcodes'   => $id > 0 ? (new Barcode())->forProduct($id) : [],
            'unitTypes'  => ProductService::UNIT_TYPES,
            'canManage'  => Gate::allows('product.manage'),
            'canPrice'   => Gate::allows('price.manage'),
            'showCost'   => Gate::allows('product.view_cost'),
        ];
    }

    /** @return array{q: string, category_id: int, stock: string, unit: string, price_min: ?string, price_max: ?string, inactive: bool} */
    private function filters(): array
    {
        $stock = $this->query('stock');

        return [
            'q'           => mb_substr($this->query('q'), 0, 100),
            'category_id' => $this->queryInt('category_id'),
            'stock'       => in_array($stock, ['in', 'low', 'out'], true) ? $stock : '',
            'unit'        => mb_substr($this->query('unit'), 0, 30),
            'price_min'   => $this->moneyQuery('price_min'),
            'price_max'   => $this->moneyQuery('price_max'),
            'inactive'    => $this->query('inactive') === '1',
        ];
    }

    private function moneyQuery(string $key): ?string
    {
        try {
            return Pricing::parse($this->query($key), true);
        } catch (\DomainException) {
            return null;
        }
    }
}
