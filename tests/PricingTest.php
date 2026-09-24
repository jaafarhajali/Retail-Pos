<?php
declare(strict_types=1);

use App\Services\Pricing;

return [
    // Review focus 4
    'parse accepts thousands separators and a comma decimal, refuses garbage and negatives' => function (): void {
        assert_same('1250.50', Pricing::parse('1,250.50'));
        assert_same('15.50', Pricing::parse('15,5'));
        assert_same('15.00', Pricing::parse(' 15 '));
        assert_same('0.00', Pricing::parse('0'));
        assert_same(null, Pricing::parse('', true));
        foreach (['', 'abc', '-3', '1.234', '$5'] as $bad) {
            assert_throws(DomainException::class, fn () => Pricing::parse($bad));
        }
    },

    'cost per base unit from a unit cost: $200 per Box of 20,000 g = $0.010000 per g' => function (): void {
        assert_same('0.010000', Pricing::costPerBase('200.00', 20000));
        assert_same('0.010000', Pricing::costPerBase('10.00', 1000));
        assert_same('3.500000', Pricing::costPerBase('3.50', 1));
        assert_same('0.000417', Pricing::costPerBase('5.00', 12000));
    },

    'unit cost from cost per base: $0.010000 × 20,000 = $200.00' => function (): void {
        assert_same('200.00', Pricing::unitCost('0.010000', 20000));
        assert_same('10.00', Pricing::unitCost('0.010000', 1000));
    },

    'margin: cost $5, price $8 → profit $3.00, 60 % (§5)' => function (): void {
        assert_same(['profit' => '3.00', 'pct' => '60.0'], Pricing::margin('8.00', '5.00'));
        assert_same(['profit' => '-1.00', 'pct' => '-20.0'], Pricing::margin('4.00', '5.00'));
        assert_same(['profit' => '8.00', 'pct' => null], Pricing::margin('8.00', '0.00'), 'no cost → no percentage');
        assert_same(null, Pricing::margin(null, '5.00'), 'not sold at this level');
    },

    'a target margin only suggests a price' => function (): void {
        assert_same('280.00', Pricing::suggestedPrice('200.00', '40'));
        assert_same('8.00', Pricing::suggestedPrice('5.00', '60.00'));
        assert_same(null, Pricing::suggestedPrice('5.00', null));
        assert_same(null, Pricing::suggestedPrice('0.00', '40'));
    },

    'stock value = base units × cost per base' => function (): void {
        assert_same('1975.00', Pricing::stockValue(197500, '0.010000'));
        assert_same('0.00', Pricing::stockValue(0, '0.010000'));
    },
];
