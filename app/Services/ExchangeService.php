<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Product;
use App\Models\ProductUnit;

/**
 * An exchange at the till (Aya, 2026-11-06): the customer brings items back and takes others instead, in one step.
 * It is one atomic record of the two halves the shop already knows: a sale of the new items, whose cash in is the
 * return's value plus whatever he pays, and the return, whose value went out as those goods. The drawer nets to the
 * difference alone; every existing rule keeps working (paid-or-today's-lower refunds, stock both ways, and when the
 * new items cost less, the leftover follows the return rules: a credit customer's debt first, then cash back).
 */
final class ExchangeService
{
    /**
     * @param list<array> $returnItems as ReturnService::price()/priceFree() take them
     * @param list<array{product_id: int, unit_id: int, qty: string}> $newLines what he takes instead
     * @return array{sale: array, return: array, return_value: string, new_total: string}
     */
    public function create(?int $saleId, array $returnItems, ?int $customerId, array $newLines, string $level,
                           string $usdPart, string $lbpPart, string $changeCurrency, string $reason,
                           int $sessionId, int $registerId, int $userId, bool $allowShortDrawer, string $pin): array
    {
        $returns = new ReturnService();

        return Database::transaction(function () use ($returns, $saleId, $returnItems, $customerId, $newLines, $level, $usdPart, $lbpPart, $changeCurrency, $reason, $sessionId, $registerId, $userId, $allowShortDrawer, $pin): array {
            $priced = $saleId !== null && $saleId > 0 ? $returns->price($saleId, $returnItems) : $returns->priceFree($returnItems, $customerId);
            $returnValue = $priced['total'];
            $customer = $priced['sale'] !== null ? $priced['customer_id'] : $customerId;

            // The new items, priced exactly as the sale will price them: the list price of the moment × qty.
            // No discounts and no price overrides in an exchange — a plain sale does those.
            $units = new ProductUnit();
            $products = new Product();
            $lines = [];
            $newTotal = 0.0;
            foreach ($newLines as $l) {
                $unit = $units->find((int) ($l['unit_id'] ?? 0));
                if ($unit === null || (int) $unit['product_id'] !== (int) ($l['product_id'] ?? 0)) {
                    throw new \DomainException('Choose a product and one of its units on every new line.');
                }
                $qtyText = trim((string) ($l['qty'] ?? ''));
                if ($qtyText === '' || (float) $qtyText == 0.0) {
                    continue;
                }
                $qty = Quantity::parse($qtyText, (int) $unit['allows_fraction'] === 1);
                $list = $level === 'wholesale' ? $unit['wholesale_price'] : $unit['retail_price'];
                if ($list === null || (float) $list <= 0) {
                    $p = $products->find((int) $unit['product_id']);
                    throw new \DomainException(($p['name'] ?? 'The product') . " ({$unit['name']}) is not sold at the {$level} price.");
                }
                $newTotal += (float) Money::mul(Money::fmt($list), (float) $qty);
                $lines[] = ['product_id' => (int) $unit['product_id'], 'unit_id' => (int) $unit['id'], 'qty' => $qty];
            }
            if ($lines === []) {
                throw new \DomainException('Scan or type what he takes instead.');
            }
            $newTotalUsd = Money::fmt($newTotal);
            $credit = Money::fmt(min((float) $returnValue, (float) $newTotal));

            // The sale first, so its cash in already sits in the drawer when the return pays the exchanged part out.
            $payments = [['method' => 'cash', 'currency' => 'USD', 'amount' => $credit]];
            $pays = Money::cmp($newTotalUsd, $returnValue) > 0;   // the new items cost more: he pays the difference
            if ($pays) {
                if (trim($usdPart) !== '') {
                    $payments[] = ['method' => 'cash', 'currency' => 'USD', 'amount' => trim($usdPart)];
                }
                if (trim($lbpPart) !== '') {
                    $payments[] = ['method' => 'cash', 'currency' => 'LBP', 'amount' => trim($lbpPart)];
                }
            }
            $sale = (new SaleService())->complete([
                'register_id' => $registerId, 'session_id' => $sessionId, 'user_id' => $userId, 'customer_id' => $customer,
                'price_level' => $level, 'lines' => $lines, 'invoice_discount' => '', 'payments' => $payments,
                'change_currency' => $changeCurrency, 'notes' => 'Exchange: taken against a return', 'pin' => $pin,
            ]);

            $note = 'Exchange with ' . $sale['invoice_no'] . (trim($reason) !== '' ? ' · ' . trim($reason) : '');
            $ret = $returns->recordForExchange($priced, $note, $sessionId, $registerId, $userId, $allowShortDrawer,
                $pays ? null : $usdPart, $pays ? null : $lbpPart, $credit);

            Audit::log('exchange.recorded', 'return', (int) $ret['id'], [
                'return_no' => $ret['return_no'], 'invoice' => $sale['invoice_no'], 'returned' => $returnValue, 'taken' => $newTotalUsd,
            ], (float) $newTotalUsd, 'USD');

            return ['sale' => $sale, 'return' => $ret, 'return_value' => $returnValue, 'new_total' => $newTotalUsd];
        });
    }
}
