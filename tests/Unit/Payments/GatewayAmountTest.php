<?php

use App\Exceptions\GatewayUnavailable;
use App\Payments\GatewayAmount;

it('turns an amount into paise or cents', function () {
    expect(GatewayAmount::minor('708.5'))->toBe(70850)
        ->and(GatewayAmount::minor('708.50'))->toBe(70850)
        ->and(GatewayAmount::minor('0.07'))->toBe(7)
        ->and(GatewayAmount::minor('100'))->toBe(10000);
});

it('keeps whole units for a zero-decimal currency and refuses a fraction', function () {
    expect(GatewayAmount::minor('100', true))->toBe(100)
        ->and(GatewayAmount::minor('100.00', true))->toBe(100);

    GatewayAmount::minor('100.5', true);
})->throws(GatewayUnavailable::class);

it('refuses a negative amount and more than two decimals', function (string $amount) {
    GatewayAmount::minor($amount);
})->with(['-1.00', '1.005', '10.999'])->throws(GatewayUnavailable::class);

it('turns minor units back into a two decimal string', function () {
    expect(GatewayAmount::fromMinor(70850))->toBe('708.50')
        ->and(GatewayAmount::fromMinor('5'))->toBe('0.05')
        ->and(GatewayAmount::fromMinor(100, true))->toBe('100.00');
});

it('writes an amount with two decimals', function () {
    expect(GatewayAmount::twoDecimals('708.5'))->toBe('708.50')
        ->and(GatewayAmount::twoDecimals('708'))->toBe('708.00');
});
