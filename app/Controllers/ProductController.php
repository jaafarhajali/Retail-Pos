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

    /** Creates the product and, in the same transaction, its first unit, prices, cost and opening stock. */
    public function store(): void
    {
        try {
            $id = \App\Core\Database::transaction(function (): int {
                $service = new ProductService();
                $id = $service->create($this->productInput());
                $unitName = $this->input('unit_name');
                if ($unitName !== '') {
                    $unitId = $service->addUnit($id, $unitName, $this->input('unit_factor') ?: '1', isset($_POST['allows_fraction']), false);
                    if (Gate::allows('price.manage') && ($this->input('retail_price') !== '' || $this->input('wholesale_price') !== '')) {
                        $service->setPrices($unitId, $this->input('retail_price'), $this->input('wholesale_price'));
                    }
                    if (Gate::allows('product.view_cost') && $this->input('unit_cost') !== '') {
                        $unit = (new ProductUnit())->find($unitId);
                        $service->setCost($id, $this->input('unit_cost'), (int) $unit['factor']);
                    }
                    if ($this->input('opening_qty') !== '' && Gate::allows('stock.adjust')) {
                        (new \App\Services\StockService())->adjust($id, $unitId, $this->input('opening_qty'), 'opening', '', null, \App\Core\Auth::id());
                    }
                }

                return $id;
            });
        } catch (\DomainException $e) {
            $this->failBack('products/create', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Product created. Add more units, barcodes or a photo here.');
        redirect('products/edit', ['id' => $id]);
    }

    public function edit(): void
    {
        $product = (new Product())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $this->render('products/form', $this->formData($product), $product['name']);
    }

    public function update(): void
    {
        $id = $this->inputInt('product_id');
        try {
            $service = new ProductService();
            $service->update($id, $this->productInput() + ['is_active' => isset($_POST['is_active'])]);
            $service->setMinStock($id, $this->input('min_stock_qty'), $this->inputInt('min_stock_unit_id'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Product saved.');
        redirect('products/edit', ['id' => $id]);
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
    /** Route needs product.view_cost; changing it also needs product.manage. */
    public function cost(): void
    {
        if (!Gate::allows('product.manage')) {
            throw new HttpException(403);
        }
        $id = $this->inputInt('product_id');
        try {
            $factor = 1;
            $unitId = $this->inputInt('unit_id');
            if ($unitId > 0) {
                $unit = (new ProductUnit())->find($unitId);
                if ($unit === null || (int) $unit['product_id'] !== $id) {
                    throw new \DomainException('Choose one of this product\'s units.');
                }
                $factor = (int) $unit['factor'];
            }
            (new ProductService())->setCost($id, $this->input('unit_cost'), $factor);
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['unit_cost' => $e->getMessage()]);
        }
        Flash::set('success', 'Cost updated.');
        redirect('products/edit', ['id' => $id]);
    }

    public function unitStore(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->addUnit($id, $this->input('unit_name'), $this->input('unit_factor'), isset($_POST['allows_fraction']), isset($_POST['is_display']));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['unit' => $e->getMessage()]);
        }
        Flash::set('success', 'Unit added.');
        redirect('products/edit', ['id' => $id]);
    }

    public function unitUpdate(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->updateUnit($this->inputInt('unit_id'), $this->input('unit_name'), $this->input('unit_factor'), isset($_POST['allows_fraction']), isset($_POST['is_display']));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['unit' => $e->getMessage()]);
        }
        Flash::set('success', 'Unit saved.');
        redirect('products/edit', ['id' => $id]);
    }

    public function unitDelete(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->deleteUnit($this->inputInt('unit_id'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['unit' => $e->getMessage()]);
        }
        Flash::set('success', 'Unit deleted.');
        redirect('products/edit', ['id' => $id]);
    }

    public function unitDefault(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->setDefaultUnit($this->inputInt('unit_id'), $this->input('kind'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['unit' => $e->getMessage()]);
        }
        Flash::set('success', 'Default unit changed.');
        redirect('products/edit', ['id' => $id]);
    }

    public function prices(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->setPrices($this->inputInt('unit_id'), $this->input('retail_price'), $this->input('wholesale_price'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['prices' => $e->getMessage()]);
        }
        Flash::set('success', 'Prices saved.');
        redirect('products/edit', ['id' => $id]);
    }

    public function barcodeStore(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->addBarcode($this->inputInt('unit_id'), $this->input('barcode'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['barcode' => $e->getMessage()]);
        }
        Flash::set('success', 'Barcode added.');
        redirect('products/edit', ['id' => $id]);
    }

    public function barcodeDelete(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->removeBarcode($this->inputInt('barcode_id'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['barcode' => $e->getMessage()]);
        }
        Flash::set('success', 'Barcode removed.');
        redirect('products/edit', ['id' => $id]);
    }

    public function imageStore(): void
    {
        $id = $this->inputInt('product_id');
        $file = $_FILES['image'] ?? null;
        try {
            if (!is_array($file) || !is_int($file['error'] ?? null)) {
                throw new \DomainException('Choose an image file.');
            }
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new \DomainException('The image must be 5 MB or smaller.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new \DomainException('The upload failed. Try again.');
            }
            (new ProductImageService())->store($id, (string) $file['tmp_name'], (int) $file['size']);
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['image' => $e->getMessage()]);
        }
        Flash::set('success', 'Image saved.');
        redirect('products/edit', ['id' => $id]);
    }

    public function imageDelete(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductImageService())->remove($id);
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['image' => $e->getMessage()]);
        }
        Flash::set('success', 'Image removed.');
        redirect('products/edit', ['id' => $id]);
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
            'unitNames'  => ProductService::DEFAULT_UNIT_NAMES,
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
