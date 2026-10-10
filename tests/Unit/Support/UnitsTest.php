<?php

use App\Support\Units;

it('shows a data amount in binary units', function (int $bytes, string $expected) {
    expect(Units::bytes($bytes))->toBe($expected);
})->with([
    [0, '0 B'],
    [512, '512 B'],
    [1536, '1.50 KB'],
    [1_073_741_824, '1.00 GB'],
    [107_374_182_400, '100.0 GB'],
]);

it('shows a duration in minutes, hours and days', function (int $seconds, string $expected) {
    expect(Units::duration($seconds))->toBe($expected);
})->with([
    [59, '0 min'],
    [720, '12 min'],
    [3900, '1 h 05 min'],
    [93_600, '1 d 2 h'],
]);
