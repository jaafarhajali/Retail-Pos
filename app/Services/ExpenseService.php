<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\Supplier;

/** Expenses and supplier payments; drawer-paid ones are cash movements (spec §11). */
final class ExpenseService
{
    public function create(array $in, int $userId): int
    {
        $category = trim((string) ($in['category'] ?? ''));
        if ($category === '' || mb_strlen($category) > 60) {
            throw new \DomainException('Give the expense a category (rent, electricity, …).');
        }
        // Paid in USD, in LBP, or both at once: "$10 + 450,000 LBP". The older form (one amount + its currency) still works.
        if (isset($in['currency']) && !isset($in['usd']) && !isset($in['lbp'])) {
            $in[$in['currency'] === 'LBP' ? 'lbp' : 'usd'] = $in['amount'] ?? '';
        }
        $usdPaid = Pricing::parse((string) ($in['usd'] ?? ''), true) ?? '0.00';
        $lbpPaid = CashService::parseLbp((string) ($in['lbp'] ?? ''), true);
        $rate = (new ExchangeRate())->current();
        $usd = Money::fmt((float) $usdPaid + (float) Money::lbpToUsd($lbpPaid, $rate));
        if ((float) $usd <= 0) {
            throw new \DomainException('Enter an amount in USD, in LBP, or both.');
        }
        $date = (string) ($in['expense_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \DomainException('Choose the date.');
        }
        $paidFrom = ($in['paid_from'] ?? 'drawer') === 'outside' ? 'outside' : 'drawer';
        $supplierId = (int) ($in['supplier_id'] ?? 0);
        $supplier = null;
        if ($supplierId > 0) {
            $supplier = (new Supplier())->find($supplierId) ?? throw new \DomainException('Supplier not found.');
        }
        $sessionId = null;
        if ($paidFrom === 'drawer') {
            $session = (new CashSession())->openForUser($userId) ?? throw new \DomainException('Paying from the drawer needs your open cash session.');
            $sessionId = (int) $session['id'];
        }
        $description = mb_substr(trim((string) ($in['description'] ?? '')), 0, 255);

        return Database::transaction(function () use ($category, $description, $usdPaid, $lbpPaid, $usd, $rate, $paidFrom, $sessionId, $supplier, $userId, $date): int {
            $id = (new Expense())->create([
                'category' => $category, 'description' => $description === '' ? null : $description, 'usd_paid' => $usdPaid, 'lbp_paid' => $lbpPaid, 'amount_usd' => $usd,
                'exchange_rate' => $rate, 'paid_from' => $paidFrom, 'session_id' => $sessionId, 'supplier_id' => $supplier === null ? null : (int) $supplier['id'],
                'user_id' => $userId, 'expense_date' => $date,
            ]);
            if ($sessionId !== null) {   // one cash movement per currency that left the drawer
                foreach (['USD' => $usdPaid, 'LBP' => (string) $lbpPaid] as $cur => $out) {
                    if ((float) $out > 0) {
                        (new CashSession())->addMovement(['session_id' => $sessionId, 'currency' => $cur, 'amount' => '-' . $out,
                                                          'type' => $supplier === null ? 'expense' : 'supplier_payment', 'ref_type' => 'expense', 'ref_id' => $id, 'user_id' => $userId, 'note' => $category]);
                    }
                }
            }
            if ($supplier !== null) {
                (new Supplier())->addLedger((int) $supplier['id'], 'payment', '-' . $usd, null, $id, $userId, $category . ($description !== '' ? ': ' . $description : ''));
            }
            Audit::log($supplier === null ? 'expense.created' : 'supplier.paid', 'expense', $id, ['category' => $category, 'paid_from' => $paidFrom, 'usd' => $usdPaid, 'lbp' => $lbpPaid], (float) $usd, 'USD');

            return $id;
        });
    }
}
