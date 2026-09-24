<?php
declare(strict_types=1);

use App\Services\Quantity;

// Charcoal: base g. kg sells fractions, Box is the display unit.
$charcoal = [
    ['name' => 'kg',  'factor' => 1000,  'is_display' => 0, 'allows_fraction' => 1],
    ['name' => 'Box', 'factor' => 20000, 'is_display' => 1, 'allows_fraction' => 0],
];
// Tobacco: base piece. Carton and Pack are display units, Piece is the smallest.
$tobacco = [
    ['name' => 'Piece',  'factor' => 1,  'is_display' => 0, 'allows_fraction' => 0],
    ['name' => 'Pack',   'factor' => 6,  'is_display' => 1, 'allows_fraction' => 0],
    ['name' => 'Carton', 'factor' => 24, 'is_display' => 1, 'allows_fraction' => 0],
];

return [
    'parse accepts whole and decimal quantities in the usual spellings' => function (): void {
        assert_same('2', Quantity::parse('2', false));
        assert_same('2.5', Quantity::parse('2.5', true));
        assert_same('2.5', Quantity::parse('2,5', true));
        assert_same('1250', Quantity::parse(' 1 250 ', false));
        assert_same('0.148', Quantity::parse('0.148', true));
    },

    // Review focus 1
    'a fraction on a unit that forbids it is refused' => function (): void {
        $e = assert_throws(DomainException::class, fn () => Quantity::parse('2.5', false));
        assert_contains('whole', $e->getMessage());
        foreach (['', 'abc', '-1', '1.2345', '1/2'] as $bad) {
            assert_throws(DomainException::class, fn () => Quantity::parse($bad, true));
        }
    },

    'toBase converts with the unit factor: 2.5 kg = 2,500 g, 1 Box = 20,000 g' => function (): void {
        assert_same(2500, Quantity::toBase('2.5', 1000, true));
        assert_same(20000, Quantity::toBase('1', 20000, false));
        assert_same(148, Quantity::toBase('0.148', 1000, true));
        assert_throws(DomainException::class, fn () => Quantity::toBase('2.5', 20000, false));
    },

    'unitQty shows a base amount in a unit without trailing zeros' => function (): void {
        assert_same('17.5', Quantity::unitQty(17500, 1000));
        assert_same('20', Quantity::unitQty(20000, 1000));
        assert_same('0.148', Quantity::unitQty(148, 1000));
        assert_same('-3', Quantity::unitQty(-3, 1));
    },

    'format: greedy from the largest display unit, remainder in the smallest unit (§4)' => function () use ($charcoal, $tobacco): void {
        assert_same('10 Box', Quantity::format(200000, $charcoal, 'g'));
        assert_same('9 Box + 17.5 kg', Quantity::format(197500, $charcoal, 'g'));
        assert_same('0.5 kg', Quantity::format(500, $charcoal, 'g'));
        assert_same('0 kg', Quantity::format(0, $charcoal, 'g'));
        assert_same('3 Carton + 3 Pack + 4 Piece', Quantity::format(94, $tobacco, 'piece'));
        assert_same('4 Carton + 2 Piece', Quantity::format(98, $tobacco, 'piece'));
        assert_same('2 Pack', Quantity::format(12, $tobacco, 'piece'));
    },

    'format falls back to the base unit when there are no units, or the smallest unit cannot hold the remainder' => function (): void {
        assert_same('98 piece', Quantity::format(98, [], 'piece'));
        $packsOnly = [['name' => 'Pack', 'factor' => 6, 'is_display' => 1, 'allows_fraction' => 0]];
        assert_same('1 Pack + 2 piece', Quantity::format(8, $packsOnly, 'piece'));
        assert_same('-1 Pack', Quantity::format(-6, $packsOnly, 'piece'));
    },
];
