<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Settings;
use App\Models\CashSession;
use App\Models\Counter;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleReturn;

/** Cash sessions, X/Z reports, cash in/out, debt collection (spec §8, §10). */
final class CashService
{
    public function open(int $registerId, int $userId, string $openingUsd, string $openingLbp): int
    {
        $register = (new Register())->find($registerId);
        if ($register === null || (int) $register['is_active'] !== 1) {
            throw new \DomainException('This device is not linked to an active register.');
        }
        $sessions = new CashSession();
        if ($sessions->openForRegister($registerId) !== null) {
            throw new \DomainException('This register already has an open session. Close it first.');
        }
        if ($sessions->openForUser($userId) !== null) {
            throw new \DomainException('You already have an open session on another register.');
        }
        $usd = Pricing::parse($openingUsd, true) ?? '0.00';
        $lbp = self::parseLbp($openingLbp, true);

        return Database::transaction(function () use ($sessions, $registerId, $userId, $usd, $lbp): int {
            $no = Counter::format('S-', Counter::next('session'));
            $id = $sessions->create($no, $registerId, $userId);
            $sessions->addMovement(['session_id' => $id, 'currency' => 'USD', 'amount' => $usd, 'type' => 'opening', 'user_id' => $userId]);
            $sessions->addMovement(['session_id' => $id, 'currency' => 'LBP', 'amount' => (string) $lbp, 'type' => 'opening', 'user_id' => $userId]);
            Audit::log('session.opened', 'cash_session', $id, ['no' => $no, 'usd' => $usd, 'lbp' => $lbp]);

            return $id;
        });
    }

    /** Blind close: counts by denomination, then expected vs counted per currency (spec §8.3). */
    public function close(int $sessionId, array $usdCounts, array $lbpCounts, int $userId, bool $force = false): array
    {
        $sessions = new CashSession();
        $session = $sessions->find($sessionId) ?? throw new \DomainException('Session not found.');
        if ($session['status'] !== 'open') {
            throw new \DomainException('This session is already closed.');
        }
        $countedUsd = 0.0;
        $countedLbp = 0;
        $rows = [];
        foreach ([['USD', $usdCounts], ['LBP', $lbpCounts]] as [$cur, $counts]) {
            foreach ($counts as $denomination => $count) {
                $d = (int) $denomination;
                $n = trim((string) $count);
                if ($n === '') {
                    continue;
                }
                if (!ctype_digit($n) || $d <= 0) {
                    throw new \DomainException('Counts must be whole numbers.');
                }
                $rows[] = [$cur, $d, (int) $n];
                if ($cur === 'USD') {
                    $countedUsd += $d * (int) $n;
                } else {
                    $countedLbp += $d * (int) $n;
                }
            }
        }

        return Database::transaction(function () use ($sessions, $session, $sessionId, $rows, $countedUsd, $countedLbp, $userId, $force): array {
            $expected = $sessions->expected($sessionId);
            $z = Counter::format('Z-', Counter::next('z'));
            $f = [
                'expected_usd' => $expected['USD'], 'expected_lbp' => $expected['LBP'],
                'counted_usd' => Money::fmt($countedUsd), 'counted_lbp' => (string) $countedLbp,
                'diff_usd' => Money::sub(Money::fmt($countedUsd), $expected['USD']), 'diff_lbp' => (string) ($countedLbp - (int) $expected['LBP']),
                'z_no' => $z, 'closed_by' => $userId, 'force_closed' => $force,
            ];
            $sessions->close($sessionId, $f);
            foreach ($rows as [$cur, $d, $n]) {
                $sessions->addCount($sessionId, $cur, $d, $n);
            }
            Audit::log($force ? 'session.force_closed' : 'session.counted', 'cash_session', $sessionId, ['no' => $session['session_no'], 'z' => $z, 'diff_usd' => $f['diff_usd'], 'diff_lbp' => $f['diff_lbp']]);

            return $f;
        });
    }

    public function review(int $sessionId, int $userId, string $note): void
    {
        $sessions = new CashSession();
        $session = $sessions->find($sessionId) ?? throw new \DomainException('Session not found.');
        if ($session['status'] !== 'counted') {
            throw new \DomainException('Only a counted session can be reviewed.');
        }
        $sessions->review($sessionId, $userId, trim($note));
        Audit::log('session.reviewed', 'cash_session', $sessionId, ['note' => trim($note)]);
    }

    /** Cash in (float added) or cash out (to the safe). */
    public function cashInOut(int $sessionId, string $direction, string $currency, string $amount, string $note, int $userId): void
    {
        $session = (new CashSession())->find($sessionId) ?? throw new \DomainException('Session not found.');
        if ($session['status'] !== 'open') {
            throw new \DomainException('The session is closed.');
        }
        if (!in_array($currency, ['USD', 'LBP'], true) || !in_array($direction, ['in', 'out'], true)) {
            throw new \DomainException('Choose the currency and direction.');
        }
        $value = $currency === 'USD' ? Pricing::parse($amount) : (string) self::parseLbp($amount, false);
        if ((float) $value <= 0) {
            throw new \DomainException('Enter an amount.');
        }
        $note = trim($note);
        if ($note === '') {
            throw new \DomainException('Give a reason.');
        }
        (new CashSession())->addMovement(['session_id' => $sessionId, 'currency' => $currency, 'amount' => $direction === 'in' ? $value : '-' . $value,
                                          'type' => $direction === 'in' ? 'cash_in' : 'cash_out', 'user_id' => $userId, 'note' => $note]);
        Audit::log('cash.' . $direction, 'cash_session', $sessionId, ['note' => $note], (float) $value, $currency);
    }

    /** Debt payment at the till: customer ledger − and a cash movement + (spec §10). Returns the USD value. */
    public function collectDebt(int $customerId, string $currency, string $amount, int $sessionId, int $userId): string
    {
        $customers = new Customer();
        $customer = $customers->find($customerId) ?? throw new \DomainException('Customer not found.');
        $session = (new CashSession())->find($sessionId) ?? throw new \DomainException('Session not found.');
        if ($session['status'] !== 'open') {
            throw new \DomainException('The session is closed.');
        }
        $rate = (new ExchangeRate())->current();
        if ($currency === 'USD') {
            $original = Pricing::parse($amount);
            $usd = $original;
        } elseif ($currency === 'LBP') {
            $original = (string) self::parseLbp($amount, false);
            $usd = Money::lbpToUsd((int) $original, $rate);
        } else {
            throw new \DomainException('Choose USD or LBP.');
        }
        if ((float) $usd <= 0) {
            throw new \DomainException('Enter an amount.');
        }
        Database::transaction(function () use ($customers, $customer, $customerId, $currency, $original, $usd, $rate, $sessionId, $userId): void {
            $customers->addLedger(['customer_id' => $customerId, 'type' => 'payment', 'amount_usd' => '-' . $usd, 'currency' => $currency,
                                   'amount_original' => $original, 'exchange_rate' => $rate, 'session_id' => $sessionId, 'user_id' => $userId, 'note' => 'Paid at the till']);
            (new CashSession())->addMovement(['session_id' => $sessionId, 'currency' => $currency, 'amount' => $original, 'type' => 'debt_collection',
                                              'ref_type' => 'customer', 'ref_id' => $customerId, 'user_id' => $userId, 'note' => $customer['name']]);
            Audit::log('debt.collected', 'customer', $customerId, ['currency' => $currency, 'amount' => $original], (float) $usd, 'USD');
        });

        return $usd;
    }

    /** Everything the X and Z printouts show (spec §8.2). */
    public function report(int $sessionId): array
    {
        $sessions = new CashSession();
        $session = $sessions->find($sessionId) ?? throw new \DomainException('Session not found.');
        $sales = (new Sale())->sessionSummary($sessionId);
        $returns = Database::pdo()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(total_usd), 0) AS refund_usd FROM returns WHERE session_id = :s');
        $returns->execute(['s' => $sessionId]);
        $expenses = Database::pdo()->prepare('SELECT COALESCE(SUM(amount_usd), 0) FROM expenses WHERE session_id = :s');
        $expenses->execute(['s' => $sessionId]);

        return [
            'session' => $session,
            'sales' => $sales,
            'returns' => $returns->fetch() ?: ['n' => 0, 'refund_usd' => '0'],
            'expenses_usd' => Money::fmt((float) $expenses->fetchColumn()),
            'movements' => $sessions->movementTotals($sessionId),
            'expected' => $session['status'] === 'open' ? $sessions->expected($sessionId) : ['USD' => $session['expected_usd'], 'LBP' => $session['expected_lbp']],
            'counts' => $sessions->counts($sessionId),
            'denominations' => [
                'USD' => array_map('intval', explode(',', Settings::get('usd_denominations', '100,50,20,10,5,1'))),
                'LBP' => array_map('intval', explode(',', Settings::get('lbp_denominations', '100000,50000,20000,10000,5000,1000'))),
            ],
        ];
    }

    public static function parseLbp(string $input, bool $allowEmpty): int
    {
        $clean = str_replace([',', ' ', '.'], '', trim($input));
        if ($clean === '') {
            if ($allowEmpty) {
                return 0;
            }
            throw new \DomainException('Enter an amount in LBP.');
        }
        if (!preg_match('/^\d{1,13}$/', $clean)) {
            throw new \DomainException('LBP amounts are whole numbers, for example 270000.');
        }

        return (int) $clean;
    }
}
