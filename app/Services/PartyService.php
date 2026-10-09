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

    /** Code of the exception that says "this name exists": the form then offers "different person, save anyway". */
    public const SAME_NAME = 2;

    /** "03 111 222", "03-111222" and "+961 3 111 222" are one number. */
    public static function phoneKey(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '00961')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '961') && strlen($digits) > 8) {
            $digits = substr($digits, 3);
        }

        return ltrim($digits, '0');
    }

    /**
     * Two people rarely share one number, so a phone that another customer has is refused. The same name happens
     * (two Ahmad Saleh), so it is only asked about. Editing a customer checks only what was changed.
     */
    private function refuseDuplicate(Customer $customers, ?array $existing, string $name, ?string $phone, bool $allowSameName): void
    {
        $key = self::phoneKey($phone);
        $checkPhone = $key !== '' && ($existing === null || self::phoneKey($existing['phone']) !== $key);
        $checkName = $existing === null || mb_strtolower(trim((string) $existing['name'])) !== mb_strtolower($name);
        $sameName = null;
        foreach ($customers->all() as $c) {
            if ($existing !== null && (int) $c['id'] === (int) $existing['id']) {
                continue;
            }
            if ($checkPhone && self::phoneKey($c['phone']) === $key) {
                throw new \DomainException("The phone {$phone} belongs to {$c['name']}. Open that customer instead of adding a second one.");
            }
            if ($checkName && $sameName === null && mb_strtolower(trim((string) $c['name'])) === mb_strtolower($name)) {
                $sameName = $c;
            }
        }
        if ($sameName !== null && !$allowSameName) {
            $known = $sameName['phone'] !== null && $sameName['phone'] !== '' ? " (phone {$sameName['phone']})" : ' (no phone)';
            throw new \DomainException("A customer named {$sameName['name']} already exists{$known}. If this is a different person, tick \"Different person with the same name\" and save again.", self::SAME_NAME);
        }
    }

    /** A supplier that never had a purchase or a payment is removed; any other is deactivated instead (like products and categories). */
    public function deleteSupplier(int $id): void
    {
        $suppliers = new Supplier();
        $supplier = $suppliers->find($id) ?? throw new \DomainException('Supplier not found.');
        if ($suppliers->isUsed($id)) {
            throw new \DomainException("{$supplier['name']} has purchases or payments, so it cannot be deleted. Switch off \"Active\" instead.");
        }
        $suppliers->delete($id);
        Audit::log('supplier.deleted', 'supplier', $id, ['name' => $supplier['name']]);
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
        $existing = $id > 0 ? ($customers->find($id) ?? throw new \DomainException('Customer not found.')) : null;
        $this->refuseDuplicate($customers, $existing, $name, $f['phone'], (bool) ($in['allow_same_name'] ?? false));
        if ($id > 0) {
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
