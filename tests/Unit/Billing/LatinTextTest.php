<?php

use App\Billing\Countries;
use App\Billing\LatinText;

it('spots letters the invoice font cannot print', function () {
    expect([LatinText::isLatin('Rajesh Kumar'), LatinText::isLatin('राजेश'), LatinText::isLatin('José'), LatinText::isLatin(null), LatinText::isLatin('H.No 175, 2nd floor')])
        ->toBe([true, false, true, true, true]);
});

it('lists countries by ISO code', function () {
    expect(Countries::all())->toHaveKey('IN', 'India')
        ->toHaveKey('GB', 'United Kingdom')
        ->not->toHaveKey('EU')
        ->not->toHaveKey('ZZ');
});
