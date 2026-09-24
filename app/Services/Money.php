<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;

/** USD ↔ LBP at a snapshot rate, and the 5,000 LBP rounding rule (spec §7). Decimal strings in, decimal strings out. */
final class Money
{
    public static function step(): int
    {
        return max(1, (int) Settings::get('lbp_rounding_step', '5000'));
    }

    /** 26.80 USD × 90,000 = 2,412,000 LBP (whole lira, no rounding to the step). */
    public static function usdToLbp(string $usd, int $rate): int
    {
        return (int) round((float) $usd * $rate);
    }

    /** 270,000 LBP / 90,000 = 3.00 USD (cents). */
    public static function lbpToUsd(int $lbp, int $rate): string
    {
        return number_format($lbp / max(1, $rate), 2, '.', '');
    }

    /** Nearest step; an exact tie rounds up (Q3). 17,500 → 20,000 · 16,000 → 15,000. */
    public static function roundLbp(int $lbp, ?int $step = null): int
    {
        $step ??= self::step();
        $sign = $lbp < 0 ? -1 : 1;
        $abs = abs($lbp);
        $rounded = intdiv($abs + intdiv($step, 2), $step) * $step;

        return $sign * $rounded;
    }

    public static function add(string $a, string $b): string
    {
        return number_format((float) $a + (float) $b, 2, '.', '');
    }

    public static function sub(string $a, string $b): string
    {
        return number_format((float) $a - (float) $b, 2, '.', '');
    }

    public static function mul(string $a, float $factor): string
    {
        return number_format((float) $a * $factor, 2, '.', '');
    }

    public static function cmp(string $a, string $b): int
    {
        return (int) round((float) $a * 100) <=> (int) round((float) $b * 100);
    }

    public static function fmt(float|int|string $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }
}
