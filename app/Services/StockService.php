<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\ProductUnit;
use App\Models\StockMovement;

/**
 * The only writer of products.stock_base (spec §3.4). Every change is one movement row,
 * written in the same transaction, with the cost per base unit frozen on it.
 */
final class StockService
{
    public const REASONS = ['damaged', 'lost', 'expired', 'found', 'correction', 'returned-damaged', 'other'];

    /** Signed base quantity. Returns the movement id. Runs inside the caller's transaction or its own. */
    public function move(int $productId, int $baseQty, string $type, ?string $reason, ?string $costPerBase, ?string $refType, ?int $refId, ?int $userId, ?int $sessionId): int
    {
        return Database::transaction(function () use ($productId, $baseQty, $type, $reason, $costPerBase, $refType, $refId, $userId, $sessionId): int {
            $m = new StockMovement();
            $product = $m->lockProduct($productId);

            return $m->add([
                'product_id' => $productId, 'base_qty' => $baseQty, 'type' => $type, 'reason' => $reason,
                'cost_per_base' => $costPerBase ?? $product['cost_per_base'], 'ref_type' => $refType, 'ref_id' => $refId,
                'user_id' => $userId, 'session_id' => $sessionId,
            ]);
        });
    }

    /**
     * Stock in from a purchase: moving-average cost (spec §5, Q1).
     * 5 boxes @ $10 + 5 @ $15 → $12.50. Negative or zero stock takes the new cost as-is.
     */
    public function purchase(int $productId, int $baseQty, string $costPerBase, int $purchaseId, ?int $userId): void
    {
        Database::transaction(function () use ($productId, $baseQty, $costPerBase, $purchaseId, $userId): void {
            $m = new StockMovement();
            $product = $m->lockProduct($productId);
            $stock = (int) $product['stock_base'];
            $newCost = $stock > 0
                ? number_format(($stock * (float) $product['cost_per_base'] + $baseQty * (float) $costPerBase) / ($stock + $baseQty), 6, '.', '')
                : number_format((float) $costPerBase, 6, '.', '');
            $m->add(['product_id' => $productId, 'base_qty' => $baseQty, 'type' => 'purchase', 'cost_per_base' => $costPerBase,
                     'ref_type' => 'purchase', 'ref_id' => $purchaseId, 'user_id' => $userId]);
            $m->setCost($productId, $newCost);
        });
    }

    /** Opening stock, adjustment or waste typed by unit ("2 Box"). Positive adds, negative removes. */
    public function adjust(int $productId, int $unitId, string $qty, string $type, string $reason, ?string $unitCost, int $userId): int
    {
        if (!in_array($type, ['opening', 'adjustment', 'waste'], true)) {
            throw new \DomainException('Unknown stock change type.');
        }
        $factor = 1;
        $fraction = false;
        if ($unitId > 0) {
            $unit = (new ProductUnit())->find($unitId);
            if ($unit === null || (int) $unit['product_id'] !== $productId) {
                throw new \DomainException('Choose one of this product\'s units.');
            }
            $factor = (int) $unit['factor'];
            $fraction = (int) $unit['allows_fraction'] === 1;
        }
        $clean = ltrim(trim($qty), '-');
        $base = Quantity::toBase($clean, $factor, $fraction);
        if ($base === 0) {
            throw new \DomainException('Enter a quantity.');
        }
        if ($type === 'waste' || str_starts_with(trim($qty), '-')) {
            $base = -$base;
        }
        $reason = trim($reason);
        if ($type !== 'opening' && $reason === '') {
            throw new \DomainException('Give a reason.');
        }
        $cost = null;
        if ($unitCost !== null && trim($unitCost) !== '') {
            $cost = Pricing::costPerBase(Pricing::parse($unitCost), $factor);
        }

        return Database::transaction(function () use ($productId, $base, $type, $reason, $cost, $userId): int {
            $m = new StockMovement();
            $product = $m->lockProduct($productId);
            if ($cost !== null && $base > 0) {
                $stock = (int) $product['stock_base'];
                $newCost = $stock > 0
                    ? number_format(($stock * (float) $product['cost_per_base'] + $base * (float) $cost) / ($stock + $base), 6, '.', '')
                    : $cost;
                $m->setCost($productId, $newCost);
            }
            $id = $m->add(['product_id' => $productId, 'base_qty' => $base, 'type' => $type, 'reason' => $reason === '' ? null : $reason,
                           'cost_per_base' => $cost ?? $product['cost_per_base'], 'user_id' => $userId]);
            Audit::log('stock.' . $type, 'product', $productId, ['base_qty' => $base, 'reason' => $reason]);

            return $id;
        });
    }
}
