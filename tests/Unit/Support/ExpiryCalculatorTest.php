<?php

use App\Enums\ValidityUnit;
use App\Support\ExpiryCalculator;
use Carbon\CarbonImmutable;

it('gives the expiry of a recharge', function (string $mode, ?string $current, int $validity, ValidityUnit $unit, string $expected) {
    $now = CarbonImmutable::parse('2026-10-10 14:30:45');
    $currentExpiry = $current === null ? null : CarbonImmutable::parse($current);

    expect((new ExpiryCalculator($mode))->next($currentExpiry, $validity, $unit, $now)->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    'exact, still ahead: from the current expiry' => ['exact', '2026-11-01 10:00:00', 30, ValidityUnit::Days, '2026-12-01 10:00:00'],
    'exact, expired: from now' => ['exact', '2026-10-01 10:00:00', 30, ValidityUnit::Days, '2026-11-09 14:30:00'],
    'exact, never recharged: from now' => ['exact', null, 30, ValidityUnit::Days, '2026-11-09 14:30:00'],
    'midnight, still ahead: end of the last day' => ['midnight', '2026-11-01 10:00:00', 30, ValidityUnit::Days, '2026-12-02 00:00:00'],
    'midnight, expired: end of the last day' => ['midnight', '2026-10-01 10:00:00', 30, ValidityUnit::Days, '2026-11-10 00:00:00'],
    'midnight, already at midnight: stays' => ['midnight', '2026-11-10 00:00:00', 30, ValidityUnit::Days, '2026-12-10 00:00:00'],
    'hours from now' => ['exact', null, 3, ValidityUnit::Hours, '2026-10-10 17:30:00'],
    'hours from the current expiry' => ['exact', '2026-10-10 16:00:00', 3, ValidityUnit::Hours, '2026-10-10 19:00:00'],
    'hours ignore midnight mode' => ['midnight', null, 3, ValidityUnit::Hours, '2026-10-10 17:30:00'],
]);
