<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Models\ExchangeRate;

final class ExchangeRateService
{
    public const MIN = 1000;
    public const MAX = 10000000;

    /** @return int the new rate. Accepts "89500", "89,500" and "89 500". */
    public function set(string $input): int
    {
        $digits = (string) preg_replace('/[\s,]/', '', $input);
        if (!preg_match('/^\d{1,9}$/', $digits)) {
            throw new \DomainException('Enter the rate as a whole number of LBP, for example 90000.');
        }
        $rate = (int) $digits;
        if ($rate < self::MIN || $rate > self::MAX) {
            throw new \DomainException('The rate must be between 1,000 and 10,000,000 LBP per USD.');
        }
        $rates = new ExchangeRate();
        $old = $rates->current();
        if ($rate === $old) {
            throw new \DomainException('The rate is already ' . number_format($old) . ' LBP.');
        }
        $rates->add($rate, Auth::id());
        Audit::log('rate.changed', 'exchange_rate', null, ['old' => $old, 'new' => $rate]);

        return $rate;
    }
}
