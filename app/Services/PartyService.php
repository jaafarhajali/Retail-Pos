<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Models\Customer;
use App\Models\Supplier;

/** Suppliers and customers (spec §3.5). Throws \DomainException with the message to show. */
final class PartyService
{
    public function saveSupplier(int $id, string $name, string $phone, string $notes, bool $active): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \DomainException('Supplier name must be 1–120 characters.');
        }
        $suppliers = new Supplier();
        if ($suppliers->nameExists($name, $id)) {
            throw new \DomainException('A supplier with that name already exists.');
        }
        $phone = trim($phone) === '' ? null : mb_substr(trim($phone), 0, 30);
        $notes = trim($notes) === '' ? null : mb_substr(trim($notes), 0, 1000);
        if ($id > 0) {
            $suppliers->find($id) ?? throw new \DomainException('Supplier not found.');
            $suppliers->update($id, $name, $phone, $notes, $active);
            Audit::log('supplier.updated', 'supplier', $id, ['name' => $name]);

            return $id;
        }
        $id = $suppliers->create($name, $phone, $notes);
        Audit::log('supplier.created', 'supplier', $id, ['name' => $name]);

        return $id;
    }

    public function saveCustomer(int $id, array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \DomainException('Customer name must be 1–120 characters.');
        }
        $level = (string) ($in['default_price_level'] ?? 'retail');
        if (!in_array($level, ['retail', 'wholesale'], true)) {
            throw new \DomainException('Choose a price level.');
        }
        $limit = Pricing::parse((string) ($in['credit_limit_usd'] ?? ''), true);
        $f = [
            'name' => $name, 'phone' => trim((string) ($in['phone'] ?? '')) === '' ? null : mb_substr(trim((string) $in['phone']), 0, 30),
            'notes' => trim((string) ($in['notes'] ?? '')) === '' ? null : mb_substr(trim((string) $in['notes']), 0, 1000),
            'default_price_level' => $level, 'credit_limit_usd' => $limit, 'is_active' => (bool) ($in['is_active'] ?? true),
        ];
        $customers = new Customer();
        if ($id > 0) {
            $customers->find($id) ?? throw new \DomainException('Customer not found.');
            $customers->update($id, $f);
            Audit::log('customer.updated', 'customer', $id, ['name' => $name]);

            return $id;
        }
        $id = $customers->create($f);
        Audit::log('customer.created', 'customer', $id, ['name' => $name]);

        return $id;
    }

    /** Manual ledger correction (positive = customer owes more). */
    public function adjustCustomer(int $customerId, string $amount, string $note, int $userId): void
    {
        $customers = new Customer();
        $customers->find($customerId) ?? throw new \DomainException('Customer not found.');
        $clean = ltrim(trim($amount), '-');
        $usd = Pricing::parse($clean);
        if ((float) $usd == 0.0) {
            throw new \DomainException('Enter an amount.');
        }
        $signed = str_starts_with(trim($amount), '-') ? '-' . $usd : $usd;
        $note = trim($note);
        if ($note === '') {
            throw new \DomainException('Give a reason for the adjustment.');
        }
        $customers->addLedger(['customer_id' => $customerId, 'type' => 'adjustment', 'amount_usd' => $signed, 'user_id' => $userId, 'note' => $note]);
        Audit::log('customer.adjusted', 'customer', $customerId, ['note' => $note], (float) $signed, 'USD');
    }
}
