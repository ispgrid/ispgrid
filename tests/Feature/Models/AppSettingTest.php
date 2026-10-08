<?php

use App\Models\AppSetting;

it('returns the default when a setting has never been saved', function () {
    expect(AppSetting::valueOf('currency_code', 'INR'))->toBe('INR');
});

it('replaces the value of a setting that already exists', function () {
    AppSetting::put('currency_code', 'INR');
    AppSetting::put('currency_code', 'USD');

    expect(AppSetting::valueOf('currency_code'))->toBe('USD')
        ->and(AppSetting::query()->where('key', 'currency_code')->count())->toBe(1);
});
