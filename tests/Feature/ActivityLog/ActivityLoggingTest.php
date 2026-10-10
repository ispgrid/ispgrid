<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\RadiusSetting;
use App\Models\User;
use App\Support\ActivityFormatter;
use Database\Seeders\RadiusSettingSeeder;
use Spatie\Activitylog\Models\Activity;

it('records who changed an app setting, with the old and new value', function () {
    AppSetting::put('currency_code', 'INR');
    $staff = User::factory()->create();
    $this->actingAs($staff);

    AppSetting::put('currency_code', 'USD');

    $activity = Activity::query()->latest('id')->firstOrFail();
    $changes = collect($activity->attribute_changes)->toArray();

    expect($activity->event)->toBe('updated')
        ->and($activity->causer->is($staff))->toBeTrue()
        ->and($activity->subject->key)->toBe('currency_code')
        ->and($changes['old']['value'])->toBe('INR')
        ->and($changes['attributes']['value'])->toBe('USD');
});

it('records nothing when a save changes nothing', function () {
    AppSetting::put('currency_code', 'INR');
    $before = Activity::query()->count();

    AppSetting::put('currency_code', 'INR');

    expect(Activity::query()->count())->toBe($before);
});

it('never writes passwords or two-factor secrets to the activity log', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);

    $staff->update(['password' => 'a-new-password-123']);
    $staff->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
    ])->save();

    $logged = Activity::query()->get()
        ->map(fn (Activity $activity) => json_encode([$activity->attribute_changes, $activity->properties]))
        ->implode(' ');

    expect($logged)->not->toContain('password')
        ->and($logged)->not->toContain('two_factor')
        ->and(Activity::query()->where('subject_id', $staff->id)->pluck('event')->all())->toBe(['created']);
});

it('records a staff member being disabled and restored', function () {
    $staff = User::factory()->create();

    $staff->delete();
    $staff->restore();

    expect(Activity::query()->where('subject_type', $staff->getMorphClass())->where('subject_id', $staff->id)->pluck('event')->all())
        ->toBe(['created', 'deleted', 'restored']);
});

it('records a renamed connection type', function () {
    $type = ConnectionType::factory()->create(['name' => 'Home']);

    $type->update(['name' => 'Residential']);

    $changes = collect(Activity::query()->latest('id')->firstOrFail()->attribute_changes)->toArray();

    expect($changes['old']['name'])->toBe('Home')
        ->and($changes['attributes']['name'])->toBe('Residential');
});

it('never writes a router secret to the activity log', function () {
    $router = Nas::factory()->create(['secret' => 'FirstSecret123']);
    $router->update(['secret' => 'SecondSecret456', 'nasname' => '10.9.9.9']);

    $logged = Activity::query()->get()
        ->map(fn (Activity $activity) => json_encode([$activity->attribute_changes, $activity->properties]))
        ->implode(' ');

    expect($logged)->not->toContain('Secret123')
        ->and($logged)->not->toContain('Secret456')
        ->and($logged)->toContain('10.9.9.9');
});

it('records FreeRADIUS settings changes with the old and new value', function () {
    $this->seed(RadiusSettingSeeder::class);

    RadiusSetting::current()->update(['reject_requests' => 12]);

    $changes = collect(Activity::query()->latest('id')->firstOrFail()->attribute_changes)->toArray();

    expect($changes['old']['reject_requests'])->toBe(30)
        ->and($changes['attributes']['reject_requests'])->toBe(12);
});

it('records an IP taken out of the inventory but not every IP a range adds', function () {
    $ip = Ip::factory()->create();

    $ip->delete();
    $ip->restore();

    expect(Activity::query()->where('subject_type', $ip->getMorphClass())->pluck('event')->all())
        ->toBe(['deleted', 'restored']);
});

it('records a plan speed change with the old and new value', function () {
    $plan = Plan::factory()->create(['name' => 'Home 25', 'download' => '25M']);

    $plan->update(['download' => '50M']);

    $activity = Activity::query()->latest('id')->firstOrFail();
    $changes = collect($activity->attribute_changes)->toArray();

    expect($changes['old'])->toBe(['download' => '25M'])
        ->and($changes['attributes'])->toBe(['download' => '50M'])
        ->and(ActivityFormatter::subjectType($activity->subject_type))->toBe('Plan')
        ->and(ActivityFormatter::subjectName($activity))->toBe('Home 25');
});

it('records a customer status change under the customer name', function () {
    $account = Account::factory()->create(['username' => 'alice']);

    $account->update(['status_id' => AccountStatus::Suspended]);

    $activity = Activity::query()->latest('id')->firstOrFail();

    expect(ActivityFormatter::changes($activity))->toBe([['field' => 'status_id', 'old' => '1', 'new' => '3']])
        ->and(ActivityFormatter::subjectType($activity->subject_type))->toBe('Customer')
        ->and(ActivityFormatter::subjectName($activity))->toBe('alice');
});

it('never writes a customer password to the activity log', function () {
    $account = Account::factory()->create(['password' => 'FirstPass123']);
    $account->update(['password' => 'SecondPass456', 'fullname' => 'Alice Rao']);

    $logged = Activity::query()->get()
        ->map(fn (Activity $activity) => json_encode([$activity->attribute_changes, $activity->properties]))
        ->implode(' ');

    expect($logged)->not->toContain('Pass123')
        ->and($logged)->not->toContain('Pass456')
        ->and($logged)->toContain('Alice Rao');
});

it('names the status change, device reset and unlock events', function (string $event, string $label) {
    $activity = activity()->performedOn(Account::factory()->create())->event($event)->log($event);

    expect(ActivityFormatter::eventLabel($activity))->toBe($label);
})->with([
    ['status-changed', 'Status changed'],
    ['devices-reset', 'Devices reset'],
    ['unlocked', 'Unlocked'],
    ['password-changed', 'Password changed'],
    ['imported', 'Imported'],
]);
