<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
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
    public const DEFAULT_UNIT_NAMES = ['Piece', 'Pack', 'Box', 'Dozen', 'kg', 'g', 'L', 'ml'];

    /**
     * The unit types an administrator can pick; there is no free text. Each type lists the base units it fits
     * and, for the plain measures, its fixed factor. Containers (Pack, Box…) ask how many base units they hold.
     * Carton, Case, Can and Set left the list on 2026-10-07 (a carton or a case is a bigger Box; a can or a set is
     * sold as a Piece); a unit already stored under one of those names is kept "as it is" (KEEP_TYPE).
     */
    public const UNIT_TYPES = [
        'Piece'  => ['bases' => ['piece'],            'factor' => 1],
        'Dozen'  => ['bases' => ['piece'],            'factor' => 12],
        'g'      => ['bases' => ['g'],                'factor' => 1],
        'kg'     => ['bases' => ['g'],                'factor' => 1000],
        'ml'     => ['bases' => ['ml'],               'factor' => 1],
        'L'      => ['bases' => ['ml'],               'factor' => 1000],
        'Pack'   => ['bases' => ['piece', 'g', 'ml'], 'factor' => null],
        'Box'    => ['bases' => ['piece', 'g', 'ml'], 'factor' => null],
        'Bag'    => ['bases' => ['piece', 'g', 'ml'], 'factor' => null],
        'Roll'   => ['bases' => ['piece', 'g', 'ml'], 'factor' => null],
        'Bottle' => ['bases' => ['piece', 'g', 'ml'], 'factor' => null],
    ];

    /** "Box" of 6 pieces → "Box of 6"; "Pack" of 250 g → "Pack 250g"; "Box" of 1000 g → "Box 1kg"; plain measures keep their name. */
    public static function unitLabel(string $type, int $factor, string $base): string
    {
        if ((self::UNIT_TYPES[$type]['factor'] ?? null) !== null || $factor === 1) {
            return $type;
        }
        if ($base === 'piece') {
            return "{$type} of {$factor}";
        }
        $big = $base === 'g' ? 'kg' : 'L';

        // From a thousand up it reads in kg or L: 1500 g is "1.5kg", 250 g stays "250g".
        return $factor >= 1000 ? "{$type} " . rtrim(rtrim(number_format($factor / 1000, 3, '.', ''), '0'), '.') . $big : "{$type} {$factor}{$base}";
    }

    /** The type a stored unit name was made from ("Pack 250g" → "Pack"), to preselect the dropdown. */
    public static function unitType(string $name): string
    {
        $first = explode(' ', trim($name))[0];
        foreach (array_keys(self::UNIT_TYPES) as $type) {
            if (strcasecmp($type, $first) === 0) {
                return $type;
            }
        }

        return $first;
    }

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

    /** Code of the exception that says "this name exists": the page then offers "save with the same name". */
    public const SAME_NAME = 2;

    /** The type sent for a unit whose name was typed by hand before the list existed: it is left exactly as it is. */
    public const KEEP_TYPE = '__keep';

    /** Only what is weighed or poured is sold in parts: 2.5 kg, 0.75 L. Half a piece or half a box is not a sale. */
    public static function soldInFractions(string $type): bool
    {
        return in_array($type, ['kg', 'L'], true);
    }

    /**
     * Everything the product page holds, saved at once: the product, the ways it is sold with their prices and
     * barcodes, the cost, the minimum stock and, while the product has no stock history, its opening stock.
     * Nothing is saved when any part is refused.
     *
     * units: rows keyed by the page ("u12" an existing unit, "n1" a new one), each with id, type, size,
     * size_unit, retail, wholesale, barcodes (several separated by spaces or commas). main_unit, cost_unit,
     * min_unit and opening_unit name a row by its key.
     *
     * @param array<string, mixed> $in
     * @return int the product's id
     */
    public function save(int $id, array $in, int $userId): int
    {
        $rows = $this->unitRows($in['units'] ?? null);
        if ($rows === []) {
            throw new \DomainException('Add at least one way to sell the product: Piece, kg, Box…');
        }
        $name = trim((string) ($in['name'] ?? ''));
        $current = $id > 0 ? ($this->products->find($id) ?? throw new \DomainException('Product not found.')) : null;
        $renamed = $current === null || mb_strtolower(trim((string) $current['name'])) !== mb_strtolower($name);   // saving under its own name asks nothing
        if ($name !== '' && $renamed && !($in['allow_same_name'] ?? false)) {
            $twin = $this->products->findByName($name, $id);
            if ($twin !== null) {
                throw new \DomainException("Another product is called {$twin['name']} (code {$twin['internal_code']}). If this one is different, for example another size, tick \"Save with the same name\" and save again.", self::SAME_NAME);
            }
        }

        return Database::transaction(function () use ($id, $in, $rows, $userId): int {
            if ($id === 0) {
                $id = $this->create($in);
            } else {
                $this->update($id, $in);
            }
            $product = $this->products->find($id) ?? throw new \DomainException('Product not found.');
            $ids = $this->saveUnits($product, $rows, (string) ($in['main_unit'] ?? ''));
            $factorOf = fn (string $key): ?int => isset($ids[$key]) ? (int) $this->units->find($ids[$key])['factor'] : null;

            $cost = trim((string) ($in['cost'] ?? ''));
            if ($cost !== '' && Gate::allows('product.view_cost')) {
                $this->setCost($id, $cost, $factorOf((string) ($in['cost_unit'] ?? '')) ?? 1);
            }

            $minKey = (string) ($in['min_unit'] ?? '');
            $minQty = trim((string) ($in['min_qty'] ?? ''));
            $minUnit = isset($ids[$minKey]) ? $this->units->find($ids[$minKey]) : null;
            $minBase = $minQty === '' ? null
                : Quantity::toBase($minQty, $minUnit === null ? 1 : (int) $minUnit['factor'], $minUnit !== null && (int) $minUnit['allows_fraction'] === 1);
            if ($minBase !== ($product['min_stock_base'] === null ? null : (int) $product['min_stock_base'])) {
                $this->setMinStock($id, $minQty, $minUnit === null ? 0 : (int) $minUnit['id']);
            }

            $opening = trim((string) ($in['opening_qty'] ?? ''));
            if ($opening !== '' && (float) str_replace(',', '.', $opening) > 0) {
                if ((new \App\Models\StockMovement())->hasMovements($id)) {
                    throw new \DomainException('This product already has stock history. Use "Add or correct stock" instead of an opening stock.');
                }
                if (Gate::allows('stock.adjust')) {
                    $key = (string) ($in['opening_unit'] ?? '');
                    (new StockService())->adjust($id, $ids[$key] ?? (int) array_values($ids)[0], $opening, 'opening', '', null, $userId);
                }
            }

            return $id;
        });
    }

    /** A product that was never sold, purchased, counted or moved is removed; any other is deactivated instead. */
    public function delete(int $id): void
    {
        $product = $this->products->find($id) ?? throw new \DomainException('Product not found.');
        if ($this->products->isUsed($id)) {
            throw new \DomainException("{$product['name']} has sales, purchases or stock history, so it cannot be deleted. Switch off \"Active\" to stop selling it.");
        }
        if ($product['image_file'] !== null) {
            (new ProductImageService())->remove($id);
        }
        $this->products->delete($id);
        Audit::log('product.deleted', 'product', $id, ['name' => $product['name'], 'code' => $product['internal_code']]);
    }

    public function isUsed(int $id): bool
    {
        return $this->products->isUsed($id);
    }

    /**
     * Only rows with a type count; values that are not plain text (a tampered form) are read as empty.
     *
     * @return array<string, array{id: int, type: string, size: string, size_unit: string, retail: string, wholesale: string, barcodes: list<string>}>
     */
    private function unitRows(mixed $posted): array
    {
        $rows = [];
        foreach (is_array($posted) ? $posted : [] as $key => $row) {
            $text = static fn (string $field): string => is_array($row) && is_scalar($row[$field] ?? null) ? trim((string) $row[$field]) : '';
            if ($text('type') === '') {
                continue;
            }
            $codes = preg_split('/[\s,;]+/', $text('barcodes'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $rows[(string) $key] = [
                'id' => (int) $text('id'), 'type' => $text('type'), 'size' => $text('size'), 'size_unit' => $text('size_unit'),
                'retail' => $text('retail'), 'wholesale' => $text('wholesale'), 'barcodes' => array_values(array_unique($codes)),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, int> row key => unit id
     */
    private function saveUnits(array $product, array $rows, string $mainKey): array
    {
        $pid = (int) $product['id'];
        $base = (string) $product['base_unit'];
        $existing = array_column($this->units->forProduct($pid), null, 'id');
        $locked = (new \App\Models\StockMovement())->hasMovements($pid);

        // 1. Units taken off the page.
        $kept = array_filter(array_column($rows, 'id'));
        foreach ($existing as $unitId => $unit) {
            if (in_array((int) $unitId, $kept, true)) {
                continue;
            }
            if ($this->units->isUsed((int) $unitId)) {
                throw new \DomainException("{$unit['name']} was sold or purchased before, so it stays on the product. To stop selling it, empty its prices.");
            }
            $this->deleteUnit((int) $unitId);
        }

        // 2. Barcodes that left their unit go first, so a code can move from one unit to another in one save.
        $wanted = [];
        foreach ($rows as $row) {
            if ($row['id'] > 0) {
                $wanted[$row['id']] = $row['barcodes'];
            }
        }
        $have = [];
        foreach ($this->barcodes->forProduct($pid) as $b) {
            $unitId = (int) $b['product_unit_id'];
            if (isset($existing[$unitId]) && in_array($unitId, $kept, true) && !in_array($b['barcode'], $wanted[$unitId] ?? [], true)) {
                $this->removeBarcode((int) $b['id']);
            } else {
                $have[$unitId][] = $b['barcode'];
            }
        }

        // 3. The rows themselves.
        $ids = [];
        foreach ($rows as $key => $row) {
            $keep = $row['type'] === self::KEEP_TYPE;
            if ($row['id'] > 0) {
                $unit = $existing[$row['id']] ?? throw new \DomainException('A unit on the page does not belong to this product. Reload the page and try again.');
                if (!$keep) {
                    [$type, $factor] = $this->typeAndFactor($row, $base);
                    if ($locked && $factor !== (int) $unit['factor']) {
                        throw new \DomainException("{$unit['name']} cannot change its size: the product already has stock history. Add another way to sell it instead.");
                    }
                    $label = self::unitLabel($type, $factor, $base);
                    $fraction = self::soldInFractions($type);
                    if ($label !== $unit['name'] || $factor !== (int) $unit['factor'] || $fraction !== ((int) $unit['allows_fraction'] === 1) || (int) $unit['is_display'] !== 1) {
                        $this->updateUnit($row['id'], $type, (string) $factor, $fraction, true);
                    }
                }
                $unitId = $row['id'];
            } else {
                if ($keep) {
                    throw new \DomainException('Choose what the new unit is: Piece, Box, kg…');
                }
                [$type, $factor] = $this->typeAndFactor($row, $base);
                $unitId = $this->addUnit($pid, $type, (string) $factor, self::soldInFractions($type), true);
            }
            if (Gate::allows('price.manage')) {
                $this->setPrices($unitId, $row['retail'], $row['wholesale']);
            }
            foreach ($row['barcodes'] as $code) {
                if (!in_array($code, $have[$unitId] ?? [], true)) {
                    $this->addBarcode($unitId, $code);
                }
            }
            $ids[(string) $key] = $unitId;
        }

        // 4. The till shows the main unit; purchases start with the largest one.
        $now = array_column($this->units->forProduct($pid), null, 'id');
        $main = $ids[$mainKey] ?? (int) array_values($ids)[0];
        if ((int) ($now[$main]['is_default_sale'] ?? 0) !== 1) {
            $this->setDefaultUnit($main, 'sale');
        }
        $largest = $main;
        foreach ($ids as $unitId) {
            if ((int) $now[$unitId]['factor'] > (int) $now[$largest]['factor']) {
                $largest = $unitId;
            }
        }
        if ((int) ($now[$largest]['is_default_purchase'] ?? 0) !== 1) {
            $this->setDefaultUnit($largest, 'purchase');
        }

        return $ids;
    }

    /**
     * A plain measure has its own size (kg = 1000 g). A container says how much it holds: a number of pieces,
     * or a weight or volume typed in the base unit or in its thousand ("20 kg" = 20,000 g, "0.5 L" = 500 ml).
     *
     * @return array{0: string, 1: int} the type as the list spells it, and the factor
     */
    private function typeAndFactor(array $row, string $base): array
    {
        $type = self::canonicalType($row['type']) ?? throw new \DomainException('Choose what it is sold as from the list: Piece, Box, kg…');
        $def = self::UNIT_TYPES[$type];
        if (!in_array($base, $def['bases'], true)) {
            throw new \DomainException("{$type} does not fit a product sold " . self::HOW_SOLD[$base] . '.');
        }
        if ($def['factor'] !== null) {
            return [$type, (int) $def['factor']];
        }
        $size = str_replace(' ', '', (string) $row['size']);
        if ($size === '') {
            throw new \DomainException($base === 'piece' ? "How many pieces are in one {$type}?" : "How much does one {$type} hold?");
        }
        $big = $base === 'g' ? 'kg' : 'L';
        if ($base !== 'piece' && strcasecmp((string) $row['size_unit'], $big) === 0) {
            if (!preg_match('/^\d{1,6}([.,]\d{1,3})?$/', $size)) {
                throw new \DomainException("The size of {$type} is a number of {$big}, for example 20 or 0.5.");
            }
            $factor = (int) round((float) str_replace(',', '.', $size) * 1000);
        } else {
            $factor = $this->cleanFactor($size);
        }
        if ($factor < 1) {
            throw new \DomainException("The size of {$type} must be more than zero.");
        }

        return [$type, $factor];
    }

    /** "box" → "Box"; null when the list does not have it. */
    public static function canonicalType(string $type): ?string
    {
        foreach (array_keys(self::UNIT_TYPES) as $known) {
            if (strcasecmp($known, trim($type)) === 0) {
                return $known;
            }
        }

        return null;
    }

    /** How the page calls the three base units. */
    public const HOW_SOLD = ['piece' => 'by piece', 'g' => 'by weight', 'ml' => 'by volume'];
    /** The first unit of a product becomes its default sale and purchase unit. */
    public function addUnit(int $productId, string $name, string $factor, bool $allowsFraction, bool $isDisplay): int
    {
        $product = $this->products->find($productId) ?? throw new \DomainException('Product not found.');
        $allowsFraction = $allowsFraction && self::soldInFractions(self::canonicalType($name) ?? '');
        [$name, $factorInt] = $this->resolveUnit($product, $name, $factor);
        if ($this->units->nameExists($productId, $name, 0)) {
            throw new \DomainException("This product already has a unit called {$name}.");
        }
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
        $product = $this->products->find((int) $unit['product_id']) ?? throw new \DomainException('Product not found.');
        $allowsFraction = $allowsFraction && self::soldInFractions(self::canonicalType($name) ?? '');
        [$name, $factorInt] = $this->resolveUnit($product, $name, $factor);
        if ($this->units->nameExists((int) $unit['product_id'], $name, $unitId)) {
            throw new \DomainException("This product already has a unit called {$name}.");
        }
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

    /**
     * The picked type must be in UNIT_TYPES and fit the product's base unit. Plain measures get their fixed
     * factor whatever was typed; containers need the number of base units. Returns [unit name, factor].
     *
     * @return array{0: string, 1: int}
     */
    private function resolveUnit(array $product, string $type, string $factor): array
    {
        $type = trim($type);
        $match = null;
        foreach (array_keys(self::UNIT_TYPES) as $known) {
            if (strcasecmp($known, $type) === 0) {
                $match = $known;
                break;
            }
        }
        if ($match === null) {
            throw new \DomainException('Choose a unit type from the list (Piece, Pack, Box, kg…).');
        }
        $def = self::UNIT_TYPES[$match];
        $base = (string) $product['base_unit'];
        if (!in_array($base, $def['bases'], true)) {
            throw new \DomainException("{$match} does not fit a product counted in {$base}.");
        }
        $factorInt = $def['factor'] ?? $this->cleanFactor($factor);

        return [self::unitLabel($match, $factorInt, $base), $factorInt];
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
