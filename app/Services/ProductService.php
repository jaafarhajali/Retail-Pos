<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Category;
use App\Models\Counter;
use App\Models\Product;

/**
 * Product rules (spec §3.3, §5). Throws \DomainException carrying the message to show.
 * Task 5 adds the unit, price, barcode and minimum-stock methods.
 */
final class ProductService
{
    public const BASE_UNITS = ['piece', 'g', 'ml'];
    public const CODE_PREFIX = 'P-';

    private Product $products;

    public function __construct()
    {
        $this->products = new Product();
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
