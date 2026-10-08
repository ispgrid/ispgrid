<?php

use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\RadiusAttribute;
use App\Models\RadiusSetting;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RadiusAttributeSeeder;

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

it('does not bring back a connection type the operator renamed', function () {
    $this->seed(DatabaseSeeder::class);
    ConnectionType::query()->where('name', 'Home')->firstOrFail()->update(['name' => 'Residential']);

    $this->seed(DatabaseSeeder::class);

    expect(ConnectionType::withTrashed()->where('name', 'Home')->exists())->toBeFalse();
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

it('corrects the attribute allowlist on every seed run', function () {
    $this->seed(RadiusAttributeSeeder::class);
    RadiusAttribute::query()->where('name', 'Framed-Route')->update(['type' => 'integer', 'repeatable' => false]);

    $this->seed(RadiusAttributeSeeder::class);

    expect(RadiusAttribute::query()->where('name', 'Framed-Route')->firstOrFail())
        ->type->toBe('text')
        ->repeatable->toBeTrue();
});
