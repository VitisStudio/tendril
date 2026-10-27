<?php

use VitisStudio\Tendril\Marketing\Money\Money;

it('converts to integer minor units without float drift', function () {
    expect(Money::toMinor('12.34', 'USD'))->toBe(1234)
        ->and(Money::toMinor('0.1', 'USD'))->toBe(10)
        ->and(Money::toMinor('19.999', 'USD'))->toBe(2000)
        ->and(Money::toMinor('-5.25', 'EUR'))->toBe(-525)
        ->and(Money::toMinor(0.1 + 0.2, 'USD'))->toBe(30)
        ->and(Money::toMinor('1500', 'JPY'))->toBe(1500)
        ->and(Money::toMinor('1.2345', 'KWD'))->toBe(1235)
        ->and(Money::exponent('jpy'))->toBe(0);
});

it('allocates integer amounts exactly by weight', function () {
    expect(Money::allocate(100, [1 / 3, 1 / 3, 1 / 3]))->toBe([34, 33, 33])
        ->and(array_sum(Money::allocate(9999, [0.4, 0.1, 0.1, 0.4])))->toBe(9999)
        ->and(array_sum(Money::allocate(-10, [0.5, 0.5])))->toBe(-10)
        ->and(Money::allocate(500, [1.0, 0.0]))->toBe([500, 0]);
});

it('formats minor units for display', function () {
    expect(Money::format(123456, 'USD'))->toBe('1,234.56 USD')
        ->and(Money::format(1500, 'JPY'))->toBe('1,500 JPY');
});
