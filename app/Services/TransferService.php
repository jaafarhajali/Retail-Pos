<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Counter;
use App\Models\Location;
use App\Models\ProductUnit;

/**
 * Moving stock between locations (Aya, 2026-11-09): the owner brings goods from the warehouse
 * to the shop (or back, or to any other place). One transfer = a numbered record plus, per item,
 * a movement out of one location and into the other — the total never changes, only the where.
 * What is not there cannot be moved: a transfer never drives a location below zero.
 */
final class TransferService
{
    /** @param list<array{product_id: int, unit_id: int, qty: string}> $lines */
    public function create(int $fromId, int $toId, array $lines, string $note, int $userId): array
    {
        $locations = new Location();
        $from = $locations->find($fromId) ?? throw new \DomainException('Choose where the goods come from.');
        $to = $locations->find($toId) ?? throw new \DomainException('Choose where the goods go.');
        if ($fromId === $toId) {
            throw new \DomainException('From and to are the same place.');
        }
        if ((int) $from['is_active'] !== 1 || (int) $to['is_active'] !== 1) {
            throw new \DomainException('Both locations must be active.');
        }
        $units = new ProductUnit();
        $moves = [];
        foreach ($lines as $l) {
            $unit = $units->find((int) ($l['unit_id'] ?? 0));
            if ($unit === null || (int) $unit['product_id'] !== (int) ($l['product_id'] ?? 0)) {
                throw new \DomainException('Choose a product and one of its units on every line.');
            }
            $qtyText = trim((string) ($l['qty'] ?? ''));
            if ($qtyText === '' || (float) $qtyText == 0.0) {
                continue;
            }
            $base = Quantity::toBase($qtyText, (int) $unit['factor'], (int) $unit['allows_fraction'] === 1);
            if ($base <= 0) {
                throw new \DomainException('Quantities must be above zero.');
            }
            $moves[] = ['product_id' => (int) $unit['product_id'], 'base' => $base, 'base_unit' => $unit['base_unit'],
                        'name' => $unit['product_name'], 'qty' => $qtyText, 'unit' => $unit['name']];
        }
        if ($moves === []) {
            throw new \DomainException('Add at least one item to move.');
        }

        return Database::transaction(function () use ($locations, $from, $to, $moves, $note, $userId): array {
            $no = Counter::format('TRF-', Counter::next('transfer'));
            Database::pdo()->prepare('INSERT INTO transfers (transfer_no, from_location_id, to_location_id, note, user_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$no, (int) $from['id'], (int) $to['id'], trim($note) ?: null, $userId]);
            $id = (int) Database::pdo()->lastInsertId();
            $stock = new StockService();
            foreach ($moves as $m) {
                $have = $locations->stockAt($m['product_id'], (int) $from['id']);
                if ($have < $m['base']) {
                    throw new \DomainException($m['name'] . ': only ' . number_format(max(0, $have)) . ' ' . $m['base_unit'] . ' at ' . $from['name'] . ' — a transfer cannot take more than what is there.');
                }
                $stock->move($m['product_id'], -$m['base'], 'transfer', $no . ' → ' . $to['name'], null, 'transfer', $id, $userId, null, (int) $from['id']);
                $stock->move($m['product_id'], $m['base'], 'transfer', $no . ' ← ' . $from['name'], null, 'transfer', $id, $userId, null, (int) $to['id']);
            }
            Audit::log('stock.transfer', 'transfer', $id, [
                'transfer_no' => $no, 'from' => $from['name'], 'to' => $to['name'],
                'items' => array_map(static fn (array $m): string => $m['qty'] . ' ' . $m['unit'] . ' ' . $m['name'], $moves),
            ]);

            return ['id' => $id, 'transfer_no' => $no, 'lines' => count($moves)];
        });
    }

    /** The latest transfers with their item count, newest first. */
    public function recent(int $limit = 50): array
    {
        return Database::pdo()->query(
            'SELECT t.*, f.name AS from_name, o.name AS to_name, u.username,
                    (SELECT COUNT(*) FROM stock_movements m WHERE m.ref_type = \'transfer\' AND m.ref_id = t.id AND m.base_qty > 0) AS items
             FROM transfers t JOIN locations f ON f.id = t.from_location_id JOIN locations o ON o.id = t.to_location_id
             LEFT JOIN users u ON u.id = t.user_id ORDER BY t.id DESC LIMIT ' . $limit
        )->fetchAll(\PDO::FETCH_ASSOC);
    }
}
