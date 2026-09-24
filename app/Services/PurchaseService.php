<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Counter;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Supplier;

/** Posting a purchase: stock in, moving-average cost, supplier ledger (spec §11). */
final class PurchaseService
{
    /**
     * @param array<int, array{product_id: int, unit_id: int, qty: string, unit_cost: string}> $lines
     * @return int purchase id
     */
    public function create(?int $supplierId, string $ref, string $date, array $lines, string $paidNow, string $paidFrom, string $notes, int $userId): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \DomainException('Choose the purchase date.');
        }
        $supplier = null;
        if ($supplierId !== null && $supplierId > 0) {
            $supplier = (new Supplier())->find($supplierId) ?? throw new \DomainException('Supplier not found.');
        }
        $paid = Pricing::parse($paidNow, true) ?? '0.00';
        if ((float) $paid > 0 && $supplier === null) {
            throw new \DomainException('A payment needs a supplier. Leave "paid now" empty for a cash purchase without a supplier.');
        }
        $units = new ProductUnit();
        $products = new Product();
        $prepared = [];
        $total = 0.0;
        foreach ($lines as $l) {
            $unit = $units->find((int) $l['unit_id']);
            if ($unit === null || (int) $unit['product_id'] !== (int) $l['product_id']) {
                throw new \DomainException('Choose a product and one of its units on every line.');
            }
            $product = $products->find((int) $l['product_id']) ?? throw new \DomainException('Product not found.');
            $qty = Quantity::parse((string) $l['qty'], (int) $unit['allows_fraction'] === 1);
            if ((float) $qty <= 0) {
                throw new \DomainException("Enter a quantity for {$product['name']}.");
            }
            $unitCost = Pricing::parse((string) $l['unit_cost']);
            $base = Quantity::toBase($qty, (int) $unit['factor'], (int) $unit['allows_fraction'] === 1);
            $lineTotal = Money::mul($unitCost, (float) $qty);
            $total += (float) $lineTotal;
            $prepared[] = [
                'product_id' => (int) $product['id'], 'product_unit_id' => (int) $unit['id'], 'qty' => $qty, 'base_qty' => $base,
                'unit_cost_usd' => $unitCost, 'line_total_usd' => $lineTotal, 'cost_per_base' => Pricing::costPerBase($unitCost, (int) $unit['factor']),
            ];
        }
        if ($prepared === []) {
            throw new \DomainException('Add at least one line.');
        }
        $totalUsd = Money::fmt($total);
        if (Money::cmp($paid, $totalUsd) > 0) {
            throw new \DomainException('Paid now cannot exceed the purchase total.');
        }
        $sessionId = null;
        if ((float) $paid > 0 && $paidFrom === 'drawer') {
            $session = (new CashSession())->openForUser($userId) ?? throw new \DomainException('Paying from the drawer needs your open cash session.');
            $sessionId = (int) $session['id'];
        }

        return Database::transaction(function () use ($supplier, $ref, $date, $prepared, $totalUsd, $paid, $notes, $userId, $sessionId): int {
            $no = Counter::format('PUR-', Counter::next('purchase'));
            $purchases = new Purchase();
            $id = $purchases->create([
                'purchase_no' => $no, 'supplier_id' => $supplier === null ? null : (int) $supplier['id'], 'supplier_invoice_ref' => $ref === '' ? null : mb_substr($ref, 0, 60),
                'purchase_date' => $date, 'total_usd' => $totalUsd, 'paid_now_usd' => $paid, 'notes' => $notes === '' ? null : $notes, 'user_id' => $userId, 'session_id' => $sessionId,
            ]);
            $stock = new StockService();
            foreach ($prepared as $line) {
                $purchases->addItem($id, $line);
                $stock->purchase($line['product_id'], $line['base_qty'], $line['cost_per_base'], $id, $userId);
            }
            if ($supplier !== null) {
                $suppliers = new Supplier();
                $suppliers->addLedger((int) $supplier['id'], 'purchase', $totalUsd, $id, null, $userId, $no);
                if ((float) $paid > 0) {
                    $suppliers->addLedger((int) $supplier['id'], 'payment', '-' . $paid, $id, null, $userId, 'Paid with ' . $no);
                    if ($sessionId !== null) {
                        (new CashSession())->addMovement(['session_id' => $sessionId, 'currency' => 'USD', 'amount' => '-' . $paid, 'type' => 'supplier_payment',
                                                        'ref_type' => 'purchase', 'ref_id' => $id, 'user_id' => $userId, 'note' => $no]);
                    }
                }
            }
            Audit::log('purchase.posted', 'purchase', $id, ['no' => $no, 'lines' => count($prepared)], (float) $totalUsd, 'USD');

            return $id;
        });
    }
}
