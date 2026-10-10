<?php

use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Pool;
use App\Support\CustomerDefaults;

it('gives the default pool of each type and none to IPoE', function () {
    $pppoe = Pool::factory()->create();
    $hotspot = Pool::factory()->create();
    AppSetting::put('default_pool_ppp', (string) $pppoe->id);
    AppSetting::put('default_pool_hotspot', (string) $hotspot->id);

    expect(CustomerDefaults::poolId(ServiceType::Ppp))->toBe($pppoe->id)
        ->and(CustomerDefaults::poolId(ServiceType::Hotspot))->toBe($hotspot->id)
        ->and(CustomerDefaults::poolId(ServiceType::Ipoe))->toBeNull();
});

it('gives no pool when none is set or the pool was deleted', function () {
    expect(CustomerDefaults::poolId(ServiceType::Ppp))->toBeNull();

    AppSetting::put('default_pool_ppp', (string) Pool::factory()->trashed()->create()->id);

    expect(CustomerDefaults::poolId(ServiceType::Ppp))->toBeNull();
});

it('picks the lowest free address of the default IPoE range', function () {
    $range = IpRange::factory()->create();
    AppSetting::put('default_ip_range_ipoe', (string) $range->id);

    foreach (['10.1.0.2', '10.1.0.3', '10.1.0.4', '10.1.0.10', '10.1.0.9'] as $address) {
        Ip::factory()->create(['ip_range_id' => $range->id, 'address' => $address]);
    }

    Ip::query()->where('address', '10.1.0.2')->update(['is_used' => true]);
    Account::factory()->create(['ip_id' => Ip::query()->where('address', '10.1.0.3')->value('id')]);
    Ip::query()->where('address', '10.1.0.4')->firstOrFail()->delete();

    expect(CustomerDefaults::nextIpoeIp()?->address)->toBe('10.1.0.9');
});

it('has no IPoE address when no default range is set', function () {
    Ip::factory()->create();

    expect(CustomerDefaults::nextIpoeIp())->toBeNull();
});
