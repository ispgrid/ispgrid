<?php

use App\Actions\Customers\RechargeAccount;
use App\Enums\AccountStatus;
use App\Exceptions\ExpiryChangedMeanwhile;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 14:30:00'));
    $this->staff = User::factory()->create();
    $this->actingAs($this->staff);
});

it('gives an inactive customer the plan, the expiry and a fresh usage period, and makes them Active', function () {
    $account = Account::factory()->create(['username' => 'alice', 'status_id' => AccountStatus::Inactive, 'plan_id' => null, 'expiry' => null]);
    $plan = Plan::factory()->create(['slug' => 'home', 'name' => 'Home 25']);

    $recharge = app(RechargeAccount::class)->handle($account->id, $plan->id, Carbon::parse('2026-11-09 14:30'), '599', 'Paid cash');

    expect($account->fresh())
        ->plan_id->toBe($plan->id)
        ->status_id->toBe(AccountStatus::Active)
        ->expiry->format('Y-m-d H:i')->toBe('2026-11-09 14:30')
        ->usage_since->format('Y-m-d H:i')->toBe('2026-10-10 14:30')
        ->and($recharge->only(['account_id', 'plan_id', 'note', 'user_id']))->toBe([
            'account_id' => $account->id, 'plan_id' => $plan->id, 'note' => 'Paid cash', 'user_id' => $this->staff->id,
        ])
        ->and($recharge->amount)->toBe('599.00')
        ->and($recharge->old_expiry)->toBeNull()
        ->and(DB::table('radusergroup')->where('username', 'alice')->value('groupname'))->toBe('home')
        ->and(DB::table('radcheck')->where('username', 'alice')->pluck('value', 'attribute')->only(['Acct-Status-Id', 'Expiration', 'Isp-Usage-Since'])->all())
        ->toBe(['Acct-Status-Id' => '1', 'Expiration' => '09 Nov 2026 14:30', 'Isp-Usage-Since' => '10 Oct 2026 14:30']);
});

it('logs the recharge and the status change it makes, with the remark Recharge', function () {
    $account = Account::factory()->create(['status_id' => AccountStatus::Inactive, 'plan_id' => null, 'expiry' => null]);
    $plan = Plan::factory()->create(['name' => 'Home 25']);

    app(RechargeAccount::class)->handle($account->id, $plan->id, Carbon::parse('2026-11-09 14:30'), '599', null);

    $entries = Activity::query()->where('subject_id', $account->id)->whereIn('event', ['recharged', 'status-changed'])->orderBy('id')->get();
    expect($entries->pluck('event')->all())->toBe(['recharged', 'status-changed'])
        ->and($entries[0]->attribute_changes->toArray())->toEqual([
            'old' => ['plan' => null, 'expiry' => null],
            'attributes' => ['plan' => 'Home 25', 'expiry' => '2026-11-09 14:30', 'amount' => '599', 'note' => null],
        ])
        ->and($entries[1]->attribute_changes->toArray())->toEqual([
            'old' => ['status' => 'Inactive'],
            'attributes' => ['status' => 'Active', 'remark' => 'Recharge'],
        ])
        ->and($entries->every(fn (Activity $entry): bool => $entry->causer_id === $this->staff->id))->toBeTrue();
});

it('keeps a Suspended customer Suspended', function () {
    $account = Account::factory()->suspended()->create();

    app(RechargeAccount::class)->handle($account->id, Plan::factory()->create()->id, Carbon::parse('2026-11-09 14:30'), '599', null);

    expect($account->fresh()->status_id)->toBe(AccountStatus::Suspended)
        ->and(Activity::query()->where('event', 'status-changed')->exists())->toBeFalse();
});

it('records the expiry the customer had before', function () {
    $account = Account::factory()->create(['expiry' => '2026-10-20 10:00:00', 'status_id' => AccountStatus::Active]);

    $recharge = app(RechargeAccount::class)->handle($account->id, Plan::factory()->create()->id, Carbon::parse('2026-11-19 10:00'), '599', null);

    expect($recharge->old_expiry->format('Y-m-d H:i'))->toBe('2026-10-20 10:00');
});

it('refuses a customer or a plan deleted meanwhile', function (string $deleted) {
    $account = Account::factory()->create();
    $plan = Plan::factory()->create();
    ($deleted === 'customer' ? $account : $plan)->delete();

    expect(fn () => app(RechargeAccount::class)->handle($account->id, $plan->id, Carbon::parse('2026-11-09 14:30'), '599', null))
        ->toThrow(ModelNotFoundException::class);

    expect(Recharge::query()->count())->toBe(0);
})->with(['customer', 'plan']);

it('refuses a recharge made from an expiry that changed meanwhile', function () {
    $account = Account::factory()->create(['expiry' => '2026-11-01 10:00:00']);
    $plan = Plan::factory()->create();

    expect(fn () => app(RechargeAccount::class)->handle($account->id, $plan->id, Carbon::parse('2026-11-09 14:30'), '599', null, '2026-10-20 10:00:00'))
        ->toThrow(ExpiryChangedMeanwhile::class);

    expect(Recharge::query()->count())->toBe(0);
});
