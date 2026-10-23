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

    'stock value = base units × cost per base' => function (): void {
        assert_same('1975.00', Pricing::stockValue(197500, '0.010000'));
        assert_same('0.00', Pricing::stockValue(0, '0.010000'));
    },
];
