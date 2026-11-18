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

it('reads plain amounts exactly', function (string $amount, int $minor) {
    expect(GatewayAmount::minor($amount))->toBe($minor);
})->with([
    ['0.01', 1], ['708.5', 70850], ['999999.99', 99999999], ['10.', 1000], ['.5', 50], [' 10', 1000],
]);

it('refuses what is not a plain amount', function (string $amount) {
    GatewayAmount::minor($amount);
})->with(['1e3', '-0', '1.00001', '1.00004', '0x1A', '', '.', '1,5'])->throws(GatewayUnavailable::class);

it('takes whole minor units only from a provider', function (string $minor) {
    GatewayAmount::fromMinor($minor);
})->with(['1.5', '-5', '', 'abc'])->throws(GatewayUnavailable::class);
