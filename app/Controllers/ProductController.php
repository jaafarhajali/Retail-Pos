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
        $stock = $this->query('stock');
        $filters = [
            'q'           => mb_substr($this->query('q'), 0, 100),
            'category_id' => $this->queryInt('category_id'),
            'stock'       => in_array($stock, ['in', 'low', 'out'], true) ? $stock : '',
            'unit'        => mb_substr($this->query('unit'), 0, 30),
            'price_min'   => $this->moneyQuery('price_min'),
            'price_max'   => $this->moneyQuery('price_max'),
            'inactive'    => $this->query('inactive') === '1',
        ];
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

    /** Printable A4 table of every active product, grouped by category. */
    public function print(): void
    {
        $products = (new Product())->forPrint();
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

    public function store(): void
    {
        try {
            $id = (new ProductService())->create($this->productInput());
        } catch (\DomainException $e) {
            $this->failBack('products/create', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Product created. Now add its units and prices.');
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

        return [
            'product'    => $product,
            'categories' => (new Category())->all(true),
            'units'      => $id > 0 ? (new ProductUnit())->forProduct($id) : [],
            'barcodes'   => $id > 0 ? (new Barcode())->forProduct($id) : [],
            'unitNames'  => ProductService::DEFAULT_UNIT_NAMES,
            'canManage'  => Gate::allows('product.manage'),
            'canPrice'   => Gate::allows('price.manage'),
            'showCost'   => Gate::allows('product.view_cost'),
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
