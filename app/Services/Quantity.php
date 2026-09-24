<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Quantities (spec §4). Stock is an integer of base units (piece, g, ml);
 * every sellable unit is a factor (kg = 1000 g, Box = 20000 g, Dozen = 12 piece).
 */
final class Quantity
{
    /**
     * "2", "2.5", "2,5", " 1 250 " → canonical decimal string (max 3 dp, no trailing zeros).
     * @throws \DomainException on garbage, negatives, or a fraction where the unit forbids it
     */
    public static function parse(string $qty, bool $allowsFraction): string
    {
        $clean = str_replace([' ', ','], ['', '.'], trim($qty));
        if (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', $clean)) {
            throw new \DomainException('Enter a quantity such as 2 or 2.5 (up to 3 decimals).');
        }
        $clean = str_contains($clean, '.') ? rtrim(rtrim($clean, '0'), '.') : $clean;
        if (!$allowsFraction && str_contains($clean, '.')) {
            throw new \DomainException('This unit is sold in whole numbers only.');
        }

        return $clean === '' ? '0' : $clean;
    }

    public static function toBase(string $qty, int $factor, bool $allowsFraction): int
    {
        return (int) round((float) self::parse($qty, $allowsFraction) * $factor);
    }

    /** 17500 g in kg → "17.5"; 20000 → "20". */
    public static function unitQty(int $base, int $factor): string
    {
        $text = number_format($base / $factor, 3, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * "9 Box + 17.5 kg": greedy from the largest display unit down, the remainder in
     * the smallest unit (fractional if it allows it, otherwise its whole part plus the
     * leftover in base units). No units → base units.
     * @param array<int, array<string, mixed>> $units product_units rows
     */
    public static function format(int $base, array $units, string $baseUnit): string
    {
        if ($units === []) {
            return $base . ' ' . $baseUnit;
        }
        $sign = $base < 0 ? '-' : '';
        $left = abs($base);

        usort($units, static fn (array $a, array $b): int => (int) $b['factor'] <=> (int) $a['factor']);
        $smallest = $units[array_key_last($units)];
        $parts = [];
        foreach ($units as $unit) {
            $factor = (int) $unit['factor'];
            if ((int) $unit['is_display'] !== 1 || $unit === $smallest || $factor <= 0) {
                continue;
            }
            $count = intdiv($left, $factor);
            if ($count > 0) {
                $parts[] = $count . ' ' . $unit['name'];
                $left -= $count * $factor;
            }
        }

        $factor = max(1, (int) $smallest['factor']);
        if ((int) $smallest['allows_fraction'] === 1 || $left % $factor === 0) {
            if ($left > 0 || $parts === []) {
                $parts[] = self::unitQty($left, $factor) . ' ' . $smallest['name'];
            }
        } else {
            $count = intdiv($left, $factor);
            if ($count > 0) {
                $parts[] = $count . ' ' . $smallest['name'];
            }
            $rest = $left - $count * $factor;
            if ($rest > 0 || $parts === []) {
                $parts[] = $rest . ' ' . $baseUnit;
            }
        }

        return $sign . implode(' + ', $parts);
    }
}
