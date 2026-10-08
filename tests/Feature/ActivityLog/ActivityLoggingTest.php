<?php

use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\User;
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
