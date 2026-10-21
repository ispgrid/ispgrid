<?php

use App\Billing\Decimal;

it('rounds half up, negatives away from zero too', function () {
    expect([Decimal::round('8.905', 2), Decimal::round('-0.825', 2), Decimal::round('116.82', 0), Decimal::round('99', 2), Decimal::round('-0.004', 2)])
        ->toBe(['8.91', '-0.83', '117', '99.00', '0.00']);
});

it('takes a percentage without rounding', function () {
    expect(Decimal::percentOf('99.00', '9'))->toBe('8.9100')
        ->and(Decimal::percentOf('333.33', '33.33'))->toBe('111.0988');
});

it('writes a rate without trailing zeros', function () {
    expect([Decimal::trim('9.000'), Decimal::trim('2.500'), Decimal::trim('18'), Decimal::trim('0.000')])->toBe(['9', '2.5', '18', '0']);
});

it('refuses a value that is not a number', function () {
    Decimal::round('12abc', 2);
})->throws(InvalidArgumentException::class);
