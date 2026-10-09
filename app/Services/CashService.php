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
    /** Exception code: the drawer does not hold enough of a currency (I5). The caller may confirm and continue. */
    public const SHORT_DRAWER = 3;

    /**
     * Change or a cash refund cannot hand out money the drawer does not have (S-000004 refunded 650,000 LBP from
     * 10,000 and Expected went to −640,000). Cash received in the same transaction counts. Option A of 2026-10-03:
     * a warning, not a wall — the cashier pays in the other currency, records a Cash in, or confirms she adds the money.
     *
     * @param array{USD?: float|string, LBP?: int|string} $out  cash going out of the drawer, per currency
     * @param array{USD?: float|string, LBP?: int|string} $in   cash coming in with the same transaction
     * @return string|null the warning to show, or null when the drawer has enough
     */
    public static function drawerShortfall(int $sessionId, array $out, array $in, string $what): ?string
    {
        $expected = (new CashSession())->expected($sessionId);
        foreach (['LBP', 'USD'] as $cur) {
            $need = (float) ($out[$cur] ?? 0);
            if ($need <= 0.004) {
                continue;
            }
            $has = (float) $expected[$cur] + (float) ($in[$cur] ?? 0);
            if ($need > $has + 0.004) {
                $fmt = static fn (float $v): string => $cur === 'USD' ? usd($v) : lbp($v);
                $other = $cur === 'USD' ? 'LBP' : 'USD';

                return 'The drawer has only ' . $fmt(max(0.0, $has)) . ': ' . $fmt($need) . " of {$what} cannot come out of it. "
                     . "Give it in {$other}, record a Cash in first, or continue if you are adding the money yourself.";
            }
        }

        return null;
    }

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
    /**
     * Debt paid at the till, in USD, LBP or both (2026-10-04). Less than he owes: a part payment. Within half a 5,000 LBP
     * note of it: paid in full. More: the debt is cleared and the rest is change, in LBP (rounded to 5,000) or USD
     * (whole dollars, the cents in LBP), as on a sale. Change the drawer cannot give is warned about (I5).
     *
     * @return array{paid_usd: string, change_usd: string, change_lbp: int, balance: string}
     */
    public function collectDebt(int $customerId, string $usd, string $lbp, int $sessionId, int $userId, string $changeCurrency = 'LBP', bool $allowShortDrawer = false): array
    {
        $customers = new Customer();
        $customer = $customers->find($customerId) ?? throw new \DomainException('Customer not found.');
        $session = (new CashSession())->find($sessionId) ?? throw new \DomainException('Session not found.');
        if ($session['status'] !== 'open') {
            throw new \DomainException('The session is closed.');
        }
        $rate = (new ExchangeRate())->current();
        $step = Money::step();
        $usdIn = Pricing::parse($usd, true) ?? '0.00';
        $lbpIn = self::parseLbp($lbp, true);
        $received = (float) $usdIn + (float) Money::lbpToUsd($lbpIn, $rate);
        if ($received <= 0.004) {
            throw new \DomainException('Type what he gives: in USD, in LBP, or both.');
        }
        // Floating products he took on credit are re-priced at today's price the moment he pays (owner, 2026-10-09).
        $debts = new DebtService();
        $owed = (float) $customers->balance($customerId) + (float) $debts->pending($customerId)['amount'];
        if ($owed <= 0.004) {
            throw new \DomainException($customer['name'] . ' owes nothing.');
        }

        $tolerance = ($step / 2) / $rate;
        $changeUsd = '0.00';
        $changeLbp = 0;
        if ($received < $owed - $tolerance) {
            $paid = Money::fmt($received);   // part of it
        } else {
            $paid = Money::fmt($owed);       // all of it; what is over is change
            $over = round($received - $owed, 2);
            if ($over > $tolerance) {
                if ($changeCurrency === 'USD') {
                    $whole = floor($over);
                    $changeUsd = Money::fmt($whole);
                    $rest = round($over - $whole, 2);
                    $changeLbp = $rest > 0.004 ? Money::roundLbp(Money::usdToLbp(Money::fmt($rest), $rate), $step) : 0;
                } else {
                    $changeLbp = Money::roundLbp(Money::usdToLbp(Money::fmt($over), $rate), $step);
                }
            }
        }
        $short = self::drawerShortfall($sessionId, ['USD' => $changeUsd, 'LBP' => $changeLbp], ['USD' => $usdIn, 'LBP' => $lbpIn], 'change');
        if ($short !== null && !$allowShortDrawer) {
            throw new \DomainException($short, self::SHORT_DRAWER);
        }

        $given = implode(' + ', array_filter([(float) $usdIn > 0 ? usd($usdIn) : '', $lbpIn > 0 ? lbp($lbpIn) : '']));
        Database::transaction(function () use ($customers, $customer, $customerId, $usdIn, $lbpIn, $paid, $changeUsd, $changeLbp, $rate, $sessionId, $userId, $given, $short, $debts): void {
            $debts->charge($customerId, $userId, $sessionId);
            $single = (float) $usdIn > 0 xor $lbpIn > 0;   // one currency: the ledger keeps the amount as typed
            $customers->addLedger(['customer_id' => $customerId, 'type' => 'payment', 'amount_usd' => '-' . $paid,
                                   'currency' => $single ? ((float) $usdIn > 0 ? 'USD' : 'LBP') : null,
                                   'amount_original' => $single ? ((float) $usdIn > 0 ? $usdIn : (string) $lbpIn) : null,
                                   'exchange_rate' => $rate, 'session_id' => $sessionId, 'user_id' => $userId, 'note' => 'Paid at the till' . ($single ? '' : ': ' . $given)]);
            $cash = new CashSession();
            foreach (['USD' => $usdIn, 'LBP' => (string) $lbpIn] as $cur => $in) {
                if ((float) $in > 0) {
                    $cash->addMovement(['session_id' => $sessionId, 'currency' => $cur, 'amount' => $in, 'type' => 'debt_collection',
                                        'ref_type' => 'customer', 'ref_id' => $customerId, 'user_id' => $userId, 'note' => $customer['name']]);
                }
            }
            foreach (['USD' => $changeUsd, 'LBP' => (string) $changeLbp] as $cur => $out) {
                if ((float) $out > 0) {
                    $cash->addMovement(['session_id' => $sessionId, 'currency' => $cur, 'amount' => '-' . $out, 'type' => 'change',
                                        'ref_type' => 'customer', 'ref_id' => $customerId, 'user_id' => $userId, 'note' => 'Debt change: ' . $customer['name']]);
                }
            }
            Audit::log('debt.collected', 'customer', $customerId, ['given' => $given, 'change_usd' => $changeUsd, 'change_lbp' => $changeLbp], (float) $paid, 'USD');
            if ($short !== null) {
                Audit::log('drawer.short', 'customer', $customerId, ['warning' => $short]);
            }
        });

        return ['paid_usd' => $paid, 'change_usd' => $changeUsd, 'change_lbp' => $changeLbp, 'balance' => $customers->balance($customerId)];
    }

    /** Everything the X and Z printouts show (spec §8.2). */
    public function report(int $sessionId): array
    {
        $sessions = new CashSession();
        $session = $sessions->find($sessionId) ?? throw new \DomainException('Session not found.');
        $sales = (new Sale())->sessionSummary($sessionId);
        $returns = Database::pdo()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(total_usd), 0) AS refund_usd, COALESCE(SUM(rounding_usd), 0) AS rounding_usd FROM returns WHERE session_id = :s');
        $returns->execute(['s' => $sessionId]);
        $returns = $returns->fetch() ?: ['n' => 0, 'refund_usd' => '0', 'rounding_usd' => '0'];
        // The X/Z "Rounding" line is everything the 5,000 LBP rounding kept in this shift: change on sales and LBP refunds.
        $sales['rounding'] = Money::fmt((float) ($sales['rounding'] ?? 0) + (float) $returns['rounding_usd']);
        $expenses = Database::pdo()->prepare('SELECT COALESCE(SUM(amount_usd), 0) FROM expenses WHERE session_id = :s');
        $expenses->execute(['s' => $sessionId]);

        return [
            'session' => $session,
            'sales' => $sales,
            'returns' => $returns,
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
