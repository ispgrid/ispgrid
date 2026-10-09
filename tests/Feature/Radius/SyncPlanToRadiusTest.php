<?php

use App\Actions\Radius\SyncPlanToRadius;
use App\Enums\SlotMode;
use App\Models\AddressList;
use App\Models\Plan;
use App\Models\PlanAttribute;
use App\Models\Pool;
use Illuminate\Support\Facades\DB;

/**
 * @return list<array{0: string, 1: string, 2: string}>
 */
function groupRows(string $slug): array
{
    return DB::table('radgroupreply')->where('groupname', $slug)->orderBy('id')->get()
        ->map(fn (object $row): array => [$row->attribute, $row->op, $row->value])
        ->all();
}

it('writes the speed of a plan with nothing else set', function () {
    $plan = Plan::factory()->create(['slug' => 'home_25', 'upload' => '10M', 'download' => '25M']);

    app(SyncPlanToRadius::class)->handle($plan);

    expect(groupRows('home_25'))->toBe([['Mikrotik-Rate-Limit', '=', '10M/25M']]);
});

it('writes burst, priority and minimum into one rate limit', function () {
    $plan = Plan::factory()->create([
        'slug' => 'burst', 'upload' => '25M', 'download' => '25M',
        'burst_upload' => '30M', 'burst_download' => '30M',
        'threshold_upload' => '20M', 'threshold_download' => '20M',
        'burst_time' => 10, 'priority' => 8, 'min_upload' => '5M', 'min_download' => '5M',
    ]);

    app(SyncPlanToRadius::class)->handle($plan);

    expect(groupRows('burst'))->toBe([['Mikrotik-Rate-Limit', '=', '25M/25M 30M/30M 20M/20M 10/10 8 5M/5M']]);
});

it('writes the pool and one row per address list', function () {
    $plan = Plan::factory()->create([
        'slug' => 'biz', 'upload' => '50M', 'download' => '50M',
        'pool_id' => Pool::factory()->create(['name' => 'business'])->id,
    ]);
    $plan->addressLists()->attach([
        AddressList::factory()->create(['name' => 'static_nat'])->id,
        AddressList::factory()->create(['name' => 'gaming'])->id,
    ]);

    app(SyncPlanToRadius::class)->handle($plan);

    expect(groupRows('biz'))->toBe([
        ['Mikrotik-Rate-Limit', '=', '50M/50M'],
        ['Framed-Pool', '=', 'business'],
        ['Isp-Plan-Address-List', '+=', 'gaming'],
        ['Isp-Plan-Address-List', '+=', 'static_nat'],
    ]);
});

it('never writes a deleted pool or address list', function () {
    $pool = Pool::factory()->create(['name' => 'old_pool']);
    $list = AddressList::factory()->create(['name' => 'old_list']);
    $plan = Plan::factory()->create(['slug' => 'stale', 'upload' => '5M', 'download' => '5M', 'pool_id' => $pool->id]);
    $plan->addressLists()->attach($list);
    $pool->delete();
    $list->delete();

    app(SyncPlanToRadius::class)->handle($plan);

    expect(groupRows('stale'))->toBe([['Mikrotik-Rate-Limit', '=', '5M/5M']]);
});

it('converts limits to bytes and seconds', function () {
    $plan = Plan::factory()->create([
        'slug' => 'capped', 'upload' => '20M', 'download' => '20M',
        'limit_after' => 100, 'time_limit_hours' => 100,
        'daily_data_gb' => 5, 'daily_time_hours' => 4,
        'monthly_data_gb' => 300, 'monthly_time_hours' => 200,
    ]);

    app(SyncPlanToRadius::class)->handle($plan);

    expect(array_slice(groupRows('capped'), 1))->toBe([
        ['Isp-Data-Limit', '=', '107374182400'],
        ['Isp-Time-Limit', '=', '360000'],
        ['Isp-Daily-Data', '=', '5368709120'],
        ['Isp-Daily-Time', '=', '14400'],
        ['Isp-Monthly-Data', '=', '322122547200'],
        ['Isp-Monthly-Time', '=', '720000'],
    ]);
});

it('writes the fair usage speed only when fair usage is on', function () {
    $plan = Plan::factory()->create([
        'slug' => 'fup', 'upload' => '20M', 'download' => '20M', 'limit_after' => 50,
        'is_fup' => false, 'fup_upload' => '2M', 'fup_download' => '2M',
    ]);
    $sync = app(SyncPlanToRadius::class);

    $sync->handle($plan);
    expect(array_column(groupRows('fup'), 0))->not->toContain('Isp-Fup-Rate');

    $plan->update(['is_fup' => true]);
    $sync->handle($plan);
    expect(groupRows('fup'))->toContain(['Isp-Fup-Rate', '=', '2M/2M']);
});

it('writes an access-only time slot without a slot speed', function () {
    $plan = Plan::factory()->create([
        'slug' => 'night', 'upload' => '10M', 'download' => '10M',
        'slot_mode' => SlotMode::AccessOnly, 'slot_days' => 'Mo,Tu', 'slot_from' => 22, 'slot_to' => 6,
        'slot_full_days' => true, 'slot_upload' => '50M', 'slot_download' => '50M',
    ]);

    app(SyncPlanToRadius::class)->handle($plan);

    expect(array_slice(groupRows('night'), 1))->toBe([
        ['Isp-Slot-Mode', '=', 'access-only'],
        ['Isp-Slot-Days', '=', 'Mo,Tu'],
        ['Isp-Slot-From', '=', '22'],
        ['Isp-Slot-To', '=', '6'],
        ['Isp-Slot-Full-Days', '=', '1'],
    ]);
});

it('writes the boost speed of a boost slot', function () {
    $plan = Plan::factory()->boostSlot()->create(['slug' => 'boost', 'upload' => '10M', 'download' => '10M']);

    app(SyncPlanToRadius::class)->handle($plan);

    expect(array_slice(groupRows('boost'), 1))->toBe([
        ['Isp-Slot-Mode', '=', 'boost'],
        ['Isp-Slot-Days', '=', 'Mo,Tu,We,Th,Fr,Sa,Su'],
        ['Isp-Slot-From', '=', '22'],
        ['Isp-Slot-To', '=', '6'],
        ['Isp-Slot-Rate', '=', '50M/50M'],
    ]);
});

it('writes the custom attributes last, as stored', function () {
    $plan = Plan::factory()->create(['slug' => 'custom', 'upload' => '10M', 'download' => '10M']);
    PlanAttribute::factory()->for($plan)->create(['attribute' => 'Idle-Timeout', 'op' => '=', 'value' => '300']);
    PlanAttribute::factory()->for($plan)->create(['attribute' => 'Framed-Route', 'op' => '+=', 'value' => '10.9.0.0/24 0.0.0.0 1']);

    app(SyncPlanToRadius::class)->handle($plan);

    expect(array_slice(groupRows('custom'), 1))->toBe([
        ['Idle-Timeout', '=', '300'],
        ['Framed-Route', '+=', '10.9.0.0/24 0.0.0.0 1'],
    ]);
});

it('replaces the rows of its own plan and leaves other plans alone', function () {
    DB::table('radgroupreply')->insert([
        ['groupname' => 'home', 'attribute' => 'Isp-Slot-Mode', 'op' => '=', 'value' => 'boost'],
        ['groupname' => 'other', 'attribute' => 'Mikrotik-Rate-Limit', 'op' => '=', 'value' => '1M/1M'],
    ]);
    $plan = Plan::factory()->create(['slug' => 'home', 'upload' => '10M', 'download' => '10M']);

    app(SyncPlanToRadius::class)->handle($plan);

    expect(groupRows('home'))->toBe([['Mikrotik-Rate-Limit', '=', '10M/10M']])
        ->and(groupRows('other'))->toBe([['Mikrotik-Rate-Limit', '=', '1M/1M']]);
});
