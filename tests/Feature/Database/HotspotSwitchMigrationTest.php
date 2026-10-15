<?php

use App\Models\Account;
use App\Support\Hotspot;
use Database\Seeders\RadiusSettingSeeder;

beforeEach(fn () => $this->seed(RadiusSettingSeeder::class));

it('keeps Hotspot off on a new install', function () {
    expect(Hotspot::enabled())->toBeFalse();
});

it('turns Hotspot on when an upgrade finds Hotspot customers, so none is hidden', function () {
    Account::factory()->hotspot()->create();

    (require database_path('migrations/2026_10_15_140100_add_hotspot_enabled_to_settings.php'))->up();

    expect(Hotspot::enabled())->toBeTrue();
});

it('leaves Hotspot off when only deleted Hotspot customers are found', function () {
    Account::factory()->hotspot()->trashed()->create();

    (require database_path('migrations/2026_10_15_140100_add_hotspot_enabled_to_settings.php'))->up();

    expect(Hotspot::enabled())->toBeFalse();
});
