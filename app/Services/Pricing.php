<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Money arithmetic for the catalog (spec §5). Values travel as decimal strings
 * ("15.50", "0.010000") and are stored in DECIMAL columns, never as floats.
 */
final class Pricing
{
    /**
     * "1,250.50" → "1250.50", "15,5" → "15.50". Empty is null only when allowed.
     * @throws \DomainException
     */
    public static function parse(string $money, bool $allowEmpty = false): ?string
    {
        $clean = str_replace(' ', '', trim($money));
        if ($clean === '') {
            if ($allowEmpty) {
                return null;
            }
            throw new \DomainException('Enter an amount in USD, for example 15.50.');
        }
        // "1,250.50": commas are thousands separators. "15,5": the comma is the decimal point.
        $clean = preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $clean)
            ? str_replace(',', '', $clean)
            : str_replace(',', '.', $clean);
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $clean)) {
            throw new \DomainException('Enter an amount in USD, for example 15.50 (no negatives, 2 decimals at most).');
        }

        return number_format((float) $clean, 2, '.', '');
    }

    /** $200 per Box of 20000 g → "0.010000" per g. */
    public static function costPerBase(string $unitCost, int $factor): string
    {
        return number_format((float) $unitCost / max(1, $factor), 6, '.', '');
    }

    /** "0.010000" per g × 20000 → "200.00" per Box. */
    public static function unitCost(string $costPerBase, int $factor): string
    {
        return number_format((float) $costPerBase * $factor, 2, '.', '');
    }

    /**
     * profit = price − cost; margin % = profit / cost (cost $5, price $8 → $3, 60 %).
     * @return array{profit: string, pct: ?string}|null null when not sold at this level
     */
    public static function margin(?string $price, string $unitCost): ?array
    {
        if ($price === null) {
            return null;
        }
        $profit = (float) $price - (float) $unitCost;
        $pct = (float) $unitCost > 0 ? number_format($profit / (float) $unitCost * 100, 1, '.', '') : null;

        return ['profit' => number_format($profit, 2, '.', ''), 'pct' => $pct];
    }

    /** cost × (1 + target %), rounded to cents; null without a cost or a target. */
    public static function suggestedPrice(string $unitCost, ?string $targetPct): ?string
    {
        if ($targetPct === null || (float) $unitCost <= 0) {
            return null;
        }

        return number_format((float) $unitCost * (1 + (float) $targetPct / 100), 2, '.', '');
    }

    public static function stockValue(int $stockBase, string $costPerBase): string
    {
        return number_format($stockBase * (float) $costPerBase, 2, '.', '');
    }
}
