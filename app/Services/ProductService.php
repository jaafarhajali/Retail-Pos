<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Barcode;
use App\Models\Category;
use App\Models\Counter;
use App\Models\Product;
use App\Models\ProductUnit;

/** Product, unit, price and barcode rules (spec §3.3, §5). Throws \DomainException carrying the message to show. */
final class ProductService
{
    public const BASE_UNITS = ['piece', 'g', 'ml'];
    public const CODE_PREFIX = 'P-';

    /** Offered in the unit dropdown (owner decision 2026-09-24); any other name may be typed. */
    public const DEFAULT_UNIT_NAMES = ['Piece', 'Pack', 'Box', 'Carton', 'Dozen', 'kg', 'g', 'L', 'ml'];

    private Product $products;
    private ProductUnit $units;
    private Barcode $barcodes;

    public function __construct()
    {
        $this->products = new Product();
        $this->units = new ProductUnit();
        $this->barcodes = new Barcode();
    }

    /** @param array<string, mixed> $in raw form values (booleans already cast by the controller) */
    public function create(array $in): int
    {
        $fields = $this->cleanFields($in, 0);

        return Database::transaction(function () use ($fields, $in): int {
            $fields['internal_code'] = trim((string) ($in['internal_code'] ?? '')) === ''
                ? $this->nextCode()
                : $fields['internal_code'];
            $id = $this->products->create($fields);
            Audit::log('product.created', 'product', $id, ['name' => $fields['name'], 'code' => $fields['internal_code'], 'base_unit' => $fields['base_unit']]);

            return $id;
        });
    }

    public function update(int $id, array $in): void
    {
        $product = $this->products->find($id) ?? throw new \DomainException('Product not found.');
        $fields = $this->cleanFields($in, $id);
        if (trim((string) ($in['internal_code'] ?? '')) === '') {
            $fields['internal_code'] = $product['internal_code'];   // never blank an existing code
        }
        if ($fields['base_unit'] !== $product['base_unit'] && $this->products->unitCount($id) > 0) {
            throw new \DomainException('The base unit cannot change while the product has units: their factors depend on it. Delete the units first.');
        }
        $fields['is_active'] = (bool) ($in['is_active'] ?? true);
        $this->products->update($id, $fields);
        Audit::log('product.updated', 'product', $id, ['name' => $fields['name'], 'code' => $fields['internal_code'], 'is_active' => $fields['is_active']]);
    }

    /**
     * Cost is typed per any unit ("$200 per Box"); stored per base unit ("$0.010000 per g").
     * @return string the new cost per base unit
     */
    public function setCost(int $productId, string $unitCost, int $factor = 1): string
    {
        $product = $this->products->find($productId) ?? throw new \DomainException('Product not found.');
        $entered = Pricing::parse($unitCost);
        $new = Pricing::costPerBase($entered, $factor);
        $this->products->setCost($productId, $new);
        Audit::log('product.cost_changed', 'product', $productId, [
            'old' => $product['cost_per_base'], 'new' => $new, 'entered' => $entered, 'factor' => $factor,
        ]);

        return $new;
    }

    /** The first unit of a product becomes its default sale and purchase unit. */
    public function addUnit(int $productId, string $name, string $factor, bool $allowsFraction, bool $isDisplay): int
    {
        $this->products->find($productId) ?? throw new \DomainException('Product not found.');
        $name = $this->cleanUnitName($productId, $name, 0);
        $factorInt = $this->cleanFactor($factor);
        $first = $this->units->count($productId) === 0;
        $id = $this->units->create($productId, $name, $factorInt, $allowsFraction, $isDisplay);
        if ($first) {
            $this->units->setDefault($productId, $id, 'sale');
            $this->units->setDefault($productId, $id, 'purchase');
        }
        Audit::log('product.unit_added', 'product', $productId, ['unit' => $name, 'factor' => $factorInt]);

        return $id;
    }

    public function updateUnit(int $unitId, string $name, string $factor, bool $allowsFraction, bool $isDisplay): void
    {
        $unit = $this->units->find($unitId) ?? throw new \DomainException('Unit not found.');
        $name = $this->cleanUnitName((int) $unit['product_id'], $name, $unitId);
        $factorInt = $this->cleanFactor($factor);
        if ($factorInt !== (int) $unit['factor'] && (new \App\Models\StockMovement())->hasMovements((int) $unit['product_id'])) {
            throw new \DomainException('The factor is locked because the product already has stock movements. Add a new unit instead.');
        }
        $this->units->update($unitId, $name, $factorInt, $allowsFraction, $isDisplay);
        Audit::log('product.unit_updated', 'product', (int) $unit['product_id'], [
            'unit' => $name, 'old_factor' => (int) $unit['factor'], 'factor' => $factorInt,
        ]);
    }

    /** Its barcodes go with it; if it was a default, the smallest remaining unit takes over. */
    public function deleteUnit(int $unitId): void
    {
        $unit = $this->units->find($unitId) ?? throw new \DomainException('Unit not found.');
        $productId = (int) $unit['product_id'];
        Database::transaction(function () use ($unit, $unitId, $productId): void {
            $this->units->delete($unitId);
            $remaining = $this->units->forProduct($productId);
            if ($remaining !== []) {
                if ((int) $unit['is_default_sale'] === 1) {
                    $this->units->setDefault($productId, (int) $remaining[0]['id'], 'sale');
                }
                if ((int) $unit['is_default_purchase'] === 1) {
                    $this->units->setDefault($productId, (int) $remaining[0]['id'], 'purchase');
                }
            }
        });
        Audit::log('product.unit_deleted', 'product', $productId, ['unit' => $unit['name'], 'factor' => (int) $unit['factor']]);
    }

    /** @param string $kind 'sale' or 'purchase' */
    public function setDefaultUnit(int $unitId, string $kind): void
    {
        if (!in_array($kind, ['sale', 'purchase'], true)) {
            throw new \DomainException('Unknown default kind.');
        }
        $unit = $this->units->find($unitId) ?? throw new \DomainException('Unit not found.');
        $this->units->setDefault((int) $unit['product_id'], $unitId, $kind);
        Audit::log('product.unit_updated', 'product', (int) $unit['product_id'], ['unit' => $unit['name'], 'default_' . $kind => true]);
    }

    /** Empty price = not sold in this unit at that level (spec §5). */
    public function setPrices(int $unitId, string $retail, string $wholesale): void
    {
        $unit = $this->units->find($unitId) ?? throw new \DomainException('Unit not found.');
        // 0 means "not sold at this level", the same as empty (owner decision 2026-09-24).
        $newRetail = Pricing::parse($retail, true);
        $newWholesale = Pricing::parse($wholesale, true);
        $newRetail = $newRetail !== null && (float) $newRetail <= 0 ? null : $newRetail;
        $newWholesale = $newWholesale !== null && (float) $newWholesale <= 0 ? null : $newWholesale;
        $this->units->setPrices($unitId, $newRetail, $newWholesale);
        $changes = [];
        if ($unit['retail_price'] !== $newRetail) {
            $changes['retail'] = ['old' => $unit['retail_price'], 'new' => $newRetail];
        }
        if ($unit['wholesale_price'] !== $newWholesale) {
            $changes['wholesale'] = ['old' => $unit['wholesale_price'], 'new' => $newWholesale];
        }
        if ($changes !== []) {
            Audit::log('product.price_changed', 'product', (int) $unit['product_id'], ['unit' => $unit['name']] + $changes);
        }
    }

    /** Scanner input arrives with a trailing Enter; a barcode is unique across every product. */
    public function addBarcode(int $unitId, string $barcode): int
    {
        $unit = $this->units->find($unitId) ?? throw new \DomainException('Unit not found.');
        $barcode = trim($barcode);
        if (!preg_match('/^[A-Za-z0-9-]{3,64}$/', $barcode)) {
            throw new \DomainException('A barcode is 3–64 letters, digits or dashes.');
        }
        $taken = $this->barcodes->findByBarcode($barcode);
        if ($taken !== null) {
            throw new \DomainException("Barcode {$barcode} is already used by {$taken['product_name']} ({$taken['unit_name']}).");
        }
        $id = $this->barcodes->create($unitId, $barcode);
        Audit::log('product.barcode_added', 'product', (int) $unit['product_id'], ['unit' => $unit['name'], 'barcode' => $barcode]);

        return $id;
    }

    public function removeBarcode(int $barcodeId): void
    {
        $row = $this->barcodes->find($barcodeId) ?? throw new \DomainException('Barcode not found.');
        $this->barcodes->delete($barcodeId);
        Audit::log('product.barcode_removed', 'product', (int) $row['product_id'], ['unit' => $row['unit_name'], 'barcode' => $row['barcode']]);
    }

    /** "2 Box" → 40,000 g. $unitId 0 = the base unit itself. '' clears the minimum. */
    public function setMinStock(int $productId, string $qty, int $unitId): void
    {
        $product = $this->products->find($productId) ?? throw new \DomainException('Product not found.');
        if (trim($qty) === '') {
            $this->products->setMinStock($productId, null);
            Audit::log('product.updated', 'product', $productId, ['min_stock_base' => null]);

            return;
        }
        $factor = 1;
        $allowsFraction = false;
        if ($unitId > 0) {
            $unit = $this->units->find($unitId);
            if ($unit === null || (int) $unit['product_id'] !== $productId) {
                throw new \DomainException('Choose one of this product\'s units.');
            }
            $factor = (int) $unit['factor'];
            $allowsFraction = (int) $unit['allows_fraction'] === 1;
        }
        $base = Quantity::toBase($qty, $factor, $allowsFraction);
        $this->products->setMinStock($productId, $base);
        Audit::log('product.updated', 'product', $productId, ['min_stock_base' => $base, 'base_unit' => $product['base_unit']]);
    }

    private function cleanUnitName(int $productId, string $name, int $exceptId): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 30) {
            throw new \DomainException('Unit name must be 1–30 characters.');
        }
        if ($this->units->nameExists($productId, $name, $exceptId)) {
            throw new \DomainException("This product already has a unit called {$name}.");
        }

        return $name;
    }

    /** Base units per 1 of this unit: a whole number from 1 to 999,999,999. */
    private function cleanFactor(string $factor): int
    {
        $factor = str_replace([' ', ','], '', trim($factor));
        if (!preg_match('/^\d{1,9}$/', $factor) || (int) $factor < 1) {
            throw new \DomainException('Factor must be a whole number of base units, at least 1 (kg = 1000 g, Dozen = 12 piece).');
        }

        return (int) $factor;
    }

    /** P-000001, P-000002… skipping any number an admin typed by hand. */
    private function nextCode(): string
    {
        for ($try = 0; $try < 1000; $try++) {
            $code = Counter::format(self::CODE_PREFIX, Counter::next('product'));
            if (!$this->products->codeExists($code)) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not allocate a product code.');
    }

    /** @return array<string, mixed> the validated fields the model expects (internal_code may be '' for "auto") */
    private function cleanFields(array $in, int $exceptId): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            throw new \DomainException('Product name must be 1–150 characters.');
        }

        $code = trim((string) ($in['internal_code'] ?? ''));
        if ($code !== '') {
            if (!preg_match('/^[A-Za-z0-9._-]{2,30}$/', $code)) {
                throw new \DomainException('Internal code must be 2–30 characters: letters, digits, dot, dash or underscore.');
            }
            if ($this->products->codeExists($code, $exceptId)) {
                throw new \DomainException("The code {$code} is already used by another product.");
            }
        }

        $baseUnit = (string) ($in['base_unit'] ?? '');
        if (!in_array($baseUnit, self::BASE_UNITS, true)) {
            throw new \DomainException('Choose a base unit: piece, g or ml.');
        }

        $categoryId = null;
        $rawCategory = trim((string) ($in['category_id'] ?? ''));
        if ($rawCategory !== '' && $rawCategory !== '0') {
            if (!ctype_digit($rawCategory) || (new Category())->find((int) $rawCategory) === null) {
                throw new \DomainException('Choose a valid category.');
            }
            $categoryId = (int) $rawCategory;
        }

        $description = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($description) > 1000) {
            throw new \DomainException('Description must be at most 1000 characters.');
        }

        $target = null;
        $rawTarget = trim((string) ($in['target_margin_pct'] ?? ''));
        if ($rawTarget !== '') {
            if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $rawTarget) || (float) $rawTarget > 1000) {
                throw new \DomainException('Target margin must be a percentage from 0 to 1000.');
            }
            $target = number_format((float) $rawTarget, 2, '.', '');
        }

        return [
            'category_id'          => $categoryId,
            'name'                 => $name,
            'description'          => $description === '' ? null : $description,
            'internal_code'        => $code,
            'base_unit'            => $baseUnit,
            'allow_price_override' => (bool) ($in['allow_price_override'] ?? false),
            'show_on_pos_grid'     => (bool) ($in['show_on_pos_grid'] ?? true),
            'target_margin_pct'    => $target,
        ];
    }
}
