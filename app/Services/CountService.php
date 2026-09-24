<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Counter;
use App\Models\ProductUnit;
use App\Models\StockCount;

/** Stocktaking (spec §12): snapshot, count by unit, apply the difference to the current stock. */
final class CountService
{
    public function start(string $scope, int $categoryId, string $note, int $userId): int
    {
        $counts = new StockCount();
        if ($counts->openCount() !== null) {
            throw new \DomainException('A count is already open. Confirm or cancel it first.');
        }
        $scope = $scope === 'category' ? 'category' : 'all';
        if ($scope === 'category' && $categoryId <= 0) {
            throw new \DomainException('Choose the category to count.');
        }

        return Database::transaction(function () use ($counts, $scope, $categoryId, $note, $userId): int {
            $no = Counter::format('CNT-', Counter::next('count'));
            $id = $counts->create($no, $scope, $scope === 'category' ? $categoryId : null, $userId, trim($note) ?: null);
            $sql = 'SELECT id, stock_base, cost_per_base FROM products WHERE is_active = 1' . ($scope === 'category' ? ' AND category_id = :c' : '');
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($scope === 'category' ? ['c' => $categoryId] : []);
            foreach ($stmt->fetchAll() as $p) {
                $counts->addLine($id, (int) $p['id'], (int) $p['stock_base'], $p['cost_per_base']);
            }
            Audit::log('stocktake.started', 'stock_count', $id, ['no' => $no, 'scope' => $scope]);

            return $id;
        });
    }

    /** "3 Box + 4.2 kg" arrives as several (unit, qty) pairs; an empty entry clears the line. */
    public function enter(int $countId, int $lineId, array $entries): void
    {
        $counts = new StockCount();
        $count = $counts->find($countId) ?? throw new \DomainException('Count not found.');
        if ($count['status'] !== 'open') {
            throw new \DomainException('This count is closed.');
        }
        $line = null;
        foreach ($counts->lines($countId) as $l) {
            if ((int) $l['id'] === $lineId) {
                $line = $l;
            }
        }
        if ($line === null) {
            throw new \DomainException('Line not found.');
        }
        $units = new ProductUnit();
        $total = 0;
        $any = false;
        foreach ($entries as $e) {
            $qty = trim((string) ($e['qty'] ?? ''));
            if ($qty === '') {
                continue;
            }
            $any = true;
            $unitId = (int) ($e['unit_id'] ?? 0);
            $factor = 1;
            $fraction = false;
            if ($unitId > 0) {
                $unit = $units->find($unitId);
                if ($unit === null || (int) $unit['product_id'] !== (int) $line['product_id']) {
                    throw new \DomainException('Unit does not belong to ' . $line['product_name'] . '.');
                }
                $factor = (int) $unit['factor'];
                $fraction = (int) $unit['allows_fraction'] === 1;
            }
            $total += Quantity::toBase($qty, $factor, $fraction);
        }
        $counts->setCounted($lineId, $any ? $total : null);
    }

    /** Apply counted − expected to the CURRENT stock, so sales made during the count are kept (spec §12). */
    public function confirm(int $countId, int $userId): array
    {
        $counts = new StockCount();
        $count = $counts->find($countId) ?? throw new \DomainException('Count not found.');
        if ($count['status'] !== 'open') {
            throw new \DomainException('This count is closed.');
        }

        return Database::transaction(function () use ($counts, $count, $countId, $userId): array {
            $stock = new StockService();
            $gain = 0.0;
            $loss = 0.0;
            $applied = 0;
            foreach ($counts->lines($countId) as $l) {
                if ($l['counted_base'] === null) {
                    continue;
                }
                $diff = (int) $l['counted_base'] - (int) $l['expected_base'];
                if ($diff === 0) {
                    continue;
                }
                $stock->move((int) $l['product_id'], $diff, 'stocktake', $count['count_no'], $l['cost_per_base'], 'stock_count', $countId, $userId, null);
                $value = $diff * (float) $l['cost_per_base'];
                $value > 0 ? $gain += $value : $loss += -$value;
                $applied++;
            }
            $counts->setStatus($countId, 'confirmed', $userId);
            Audit::log('stocktake.confirmed', 'stock_count', $countId, ['no' => $count['count_no'], 'lines_adjusted' => $applied, 'gain_usd' => Money::fmt($gain), 'loss_usd' => Money::fmt($loss)]);

            return ['applied' => $applied, 'gain_usd' => Money::fmt($gain), 'loss_usd' => Money::fmt($loss)];
        });
    }

    public function cancel(int $countId, int $userId): void
    {
        $counts = new StockCount();
        $count = $counts->find($countId) ?? throw new \DomainException('Count not found.');
        if ($count['status'] !== 'open') {
            throw new \DomainException('This count is closed.');
        }
        $counts->setStatus($countId, 'cancelled');
        Audit::log('stocktake.cancelled', 'stock_count', $countId, ['no' => $count['count_no'], 'by' => $userId]);
    }
}
