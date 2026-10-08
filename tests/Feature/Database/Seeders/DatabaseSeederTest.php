<?php

use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\RadiusAttribute;
use App\Models\RadiusSetting;
use Database\Seeders\DatabaseSeeder;

it('seeds the FreeRADIUS settings row with the disabled pool on expiry', function () {
    $this->seed(DatabaseSeeder::class);

    expect(RadiusSetting::current())
        ->expire_type->toBe(1)
        ->disabled_users_pool->toBe('disabled_pool');
});

it('seeds the attribute allowlist without the attributes the forms or isp own', function () {
    $this->seed(DatabaseSeeder::class);

    expect(RadiusAttribute::query()->pluck('name')->all())
        ->toContain('Framed-Route', 'Idle-Timeout')
        ->not->toContain('Mikrotik-Rate-Limit', 'Framed-Pool', 'Framed-IP-Address', 'Session-Timeout');
});

it('does not duplicate or overwrite reference data when run again', function () {
    $this->seed(DatabaseSeeder::class);
    AppSetting::put('currency_code', 'USD');
    RadiusSetting::current()->update(['disabled_users_pool' => 'blocked']);

    $this->seed(DatabaseSeeder::class);

    expect(AppSetting::valueOf('currency_code'))->toBe('USD')
        ->and(RadiusSetting::current()->disabled_users_pool)->toBe('blocked')
        ->and(RadiusSetting::query()->count())->toBe(1)
        ->and(ConnectionType::query()->where('name', 'Home')->count())->toBe(1);
});
