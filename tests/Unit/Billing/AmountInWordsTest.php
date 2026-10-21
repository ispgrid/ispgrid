<?php

use App\Billing\AmountInWords;

it('says the amount in words', function () {
    expect([
        AmountInWords::format('9440.00', 'INR', true),
        AmountInWords::format('125000.00', 'INR', true),
        AmountInWords::format('116.82', 'INR', true),
        AmountInWords::format('1250000.00', 'USD', false),
    ])->toBe([
        'Indian Rupee Nine Thousand Four Hundred Forty Only',
        'Indian Rupee One Lakh Twenty Five Thousand Only',
        'Indian Rupee One Hundred Sixteen and Paise Eighty Two Only',
        'US Dollar One Million Two Hundred Fifty Thousand Only',
    ]);
});

it('says crores, zero and other currencies', function () {
    expect([
        AmountInWords::format('123456789.00', 'INR', true),
        AmountInWords::format('0.50', 'GBP', false),
        AmountInWords::format('17.00', 'XYZ', false),
        AmountInWords::format('1000000000.00', 'EUR', false),
    ])->toBe([
        'Indian Rupee Twelve Crore Thirty Four Lakh Fifty Six Thousand Seven Hundred Eighty Nine Only',
        'Pound Sterling Zero and Pence Fifty Only',
        'XYZ Seventeen Only',
        'Euro One Billion Only',
    ]);
});
