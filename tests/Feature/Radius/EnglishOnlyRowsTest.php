<?php

use App\Actions\Radius\ReplaceRadiusValue;
use App\Actions\Radius\SyncAccountToRadius;
use App\Actions\Radius\SyncPlanToRadius;
use App\Models\Account;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\RadiusSetting;
use Database\Seeders\RadiusSettingSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The forms refuse text FreeRADIUS cannot read. These prove the last guard: whatever path a value comes by,
 * a FreeRADIUS row never gets one.
 */

it('writes no FreeRADIUS row for a customer whose username or password is not English', function (array $fields) {
    $account = Account::factory()->create(['username' => 'alice', 'password' => 'secret12', ...$fields]);

    expect(fn () => app(SyncAccountToRadius::class)->handle($account->fresh()))
        ->toThrow(InvalidArgumentException::class, 'English');

    expect(DB::table('radcheck')->count())->toBe(0)
        ->and(DB::table('radusergroup')->count())->toBe(0);
})->with([
    'Hindi username' => [['username' => 'राम']],
    'Hindi password' => [['password' => 'पासवर्ड123']],
    'line break in the password' => [['password' => "secret\n12"]],
]);

it('writes no FreeRADIUS row for a plan whose slug is not English', function () {
    $plan = Plan::factory()->create(['slug' => 'घर', 'upload' => '10M', 'download' => '25M']);

    expect(fn () => app(SyncPlanToRadius::class)->handle($plan))->toThrow(InvalidArgumentException::class, 'English');

    expect(DB::table('radgroupreply')->count())->toBe(0);
});

it('does not rename a FreeRADIUS value to one that is not English', function () {
    DB::table('radgroupreply')->insert(['groupname' => 'home', 'attribute' => 'Framed-Pool', 'op' => '=', 'value' => 'pppoe']);

    expect(fn () => app(ReplaceRadiusValue::class)->handle('radgroupreply', ['home'], 'Framed-Pool', 'pppoe', 'पूल'))
        ->toThrow(InvalidArgumentException::class, 'English');

    expect(DB::table('radgroupreply')->value('value'))->toBe('pppoe');
});

it('does not save a router FreeRADIUS loads with a name or secret that is not English', function (array $fields) {
    expect(fn () => Nas::factory()->create($fields))->toThrow(InvalidArgumentException::class, 'English');

    expect(Nas::query()->count())->toBe(0);
})->with([
    'Hindi name' => [['shortname' => 'राउटर']],
    'Hindi secret' => [['secret' => 'गुप्त12345']],
]);

it('saves a router description in any language, since FreeRADIUS does not read it', function () {
    Nas::factory()->create(['description' => 'मुख्य टावर']);

    expect(Nas::query()->value('description'))->toBe('मुख्य टावर');
});

it('does not save a disabled pool name that is not English', function () {
    $this->seed(RadiusSettingSeeder::class);
    $before = RadiusSetting::current()->disabled_users_pool;

    expect(fn () => RadiusSetting::current()->update(['disabled_users_pool' => 'पूल']))
        ->toThrow(InvalidArgumentException::class, 'English');

    expect(RadiusSetting::current()->disabled_users_pool)->toBe($before);
});
