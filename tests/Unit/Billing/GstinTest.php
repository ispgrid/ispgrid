<?php

use App\Billing\India\Gstin;
use App\Billing\India\GstStates;

it('checks a GSTIN', function () {
    expect([Gstin::isValid('09ABCDE1234F1Z5'), Gstin::isValid('99ABCDE1234F1Z5'), Gstin::isValid('09abcde1234f1z5'), Gstin::isValid('09ABCDE1234F1Y5')])
        ->toBe([true, false, false, false]);
});

it('reads the state from a GSTIN', function () {
    expect(Gstin::stateCode('07ABCDE1234F1Z5'))->toBe('07');
});

it('names a state with its code', function () {
    expect(GstStates::label('07'))->toBe('Delhi (07)')
        ->and(GstStates::codes())->toHaveCount(37)
        ->and(GstStates::codes())->toContain('10', '38')
        ->and(GstStates::codes())->not->toContain('25');
});
