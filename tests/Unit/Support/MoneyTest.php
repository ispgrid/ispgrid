<?php

use App\Support\Money;

it('groups an amount the Indian way', function () {
    expect((new Money('₹', 2, 'indian'))->format('1234567.5'))->toBe('₹12,34,567.50');
});

it('groups an amount the international way', function () {
    expect((new Money('$', 2, 'international'))->format('1234567.5'))->toBe('$1,234,567.50');
});

it('leaves out decimals when the currency has none', function () {
    expect((new Money('¥', 0, 'international'))->format('599'))->toBe('¥599');
});

it('keeps small amounts ungrouped', function () {
    expect((new Money('₹', 2, 'indian'))->format('599'))->toBe('₹599.00');
});
