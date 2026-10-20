<?php

use App\Actions\Customers\RechargeAccount;
use App\Enums\AccountStatus;
use App\Enums\RechargeKind;
use App\Exceptions\ExpiryChangedMeanwhile;
use App\Exceptions\FirstLoginPlanNeedsHotspot;
use App\Models\Account;
use App\Models\AdvanceRenewal;
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

it('leaves a first-login validity waiting when the Hotspot customer has no running clock', function (?string $expiry) {
    $account = Account::factory()->hotspot()->inactive()->create([
        'username' => 'hs1', 'expiry' => $expiry, 'hotspot_first_login_at' => '2026-09-01 10:00:00',
    ]);

    $recharge = app(RechargeAccount::class)->handle($account->id, Plan::factory()->hours(3)->firstLogin()->create()->id, null, '20', null);

    expect($account->fresh())
        ->expiry->toBeNull()
        ->hotspot_pending_validity->toBe(180)
        ->hotspot_first_login_at->toBeNull()
        ->status_id->toBe(AccountStatus::Active)
        ->usage_since->format('Y-m-d H:i')->toBe('2026-10-10 14:30')
        ->and($recharge->new_expiry)->toBeNull()
        ->and($recharge->new_pending_validity)->toBe(180)
        ->and(DB::table('radcheck')->where('username', 'hs1')->pluck('value', 'attribute')->only(['Expiration', 'Hotspot-First-Login-Validity'])->all())
        ->toBe(['Hotspot-First-Login-Validity' => '180']);
})->with(['never recharged' => [null], 'expired' => ['2026-10-01 10:00:00']]);

it('adds up first-login validities sold before the first login', function () {
    $account = Account::factory()->hotspotPending(180)->create();

    $recharge = app(RechargeAccount::class)->handle($account->id, Plan::factory()->hours(3)->firstLogin()->create()->id, null, '20', null);

    expect($account->fresh()->hotspot_pending_validity)->toBe(360)->and($recharge->new_pending_validity)->toBe(360);
});

it('extends a running clock like any recharge', function () {
    $account = Account::factory()->hotspot()->create(['expiry' => '2026-10-10 16:00:00']);

    app(RechargeAccount::class)->handle($account->id, Plan::factory()->hours(3)->firstLogin()->create()->id, Carbon::parse('2026-10-10 19:00'), '20', null);

    expect($account->fresh())
        ->expiry->format('Y-m-d H:i')->toBe('2026-10-10 19:00')
        ->hotspot_pending_validity->toBeNull();
});

it('clears a waiting validity when the customer is recharged with a normal plan', function () {
    $account = Account::factory()->hotspotPending(180)->create();

    app(RechargeAccount::class)->handle($account->id, Plan::factory()->create()->id, Carbon::parse('2026-11-09 14:30'), '599', null);

    expect($account->fresh())
        ->expiry->format('Y-m-d H:i')->toBe('2026-11-09 14:30')
        ->hotspot_pending_validity->toBeNull();
});

it('refuses a first-login plan for a PPPoE or IPoE customer', function (Closure $make) {
    $account = $make();

    expect(fn () => app(RechargeAccount::class)->handle($account->id, Plan::factory()->hours(3)->firstLogin()->create()->id, Carbon::parse('2026-10-10 17:30'), '20', null))
        ->toThrow(FirstLoginPlanNeedsHotspot::class);
    expect(Recharge::query()->count())->toBe(0);
})->with([
    'PPPoE' => [fn () => Account::factory()->create()],
    'IPoE' => [fn () => Account::factory()->ipoe()->create()],
]);

it('needs an expiry unless the validity waits for the first login', function () {
    $account = Account::factory()->create();

    expect(fn () => app(RechargeAccount::class)->handle($account->id, Plan::factory()->create()->id, null, '599', null))
        ->toThrow(InvalidArgumentException::class);
});

it('logs a first-login recharge as validity from the first login', function () {
    $account = Account::factory()->hotspotPending(180)->create();

    app(RechargeAccount::class)->handle($account->id, Plan::factory()->hours(3)->firstLogin()->create(['name' => 'Hotspot 3h'])->id, null, '20', null);

    $entry = Activity::query()->where('subject_id', $account->id)->where('event', 'recharged')->sole();
    expect($entry->attribute_changes['old']['expiry'])->toBe('3 hours from first login')
        ->and($entry->attribute_changes['attributes']['expiry'])->toBe('6 hours from first login');
});

it('remembers a waiting validity in the expiry key', function () {
    expect(RechargeAccount::expiryKey(Account::factory()->hotspotPending(180)->make()))->toBe('pending:180')
        ->and(RechargeAccount::expiryKey(Account::factory()->make(['expiry' => '2026-11-01 10:00:00'])))->toBe('2026-11-01 10:00:00')
        ->and(RechargeAccount::expiryKey(Account::factory()->make(['expiry' => null])))->toBe('');
});

it('stops when FreeRADIUS started the clock after the expiry key was taken', function () {
    $account = Account::factory()->hotspotPending(180)->create();
    $key = RechargeAccount::expiryKey($account);
    DB::table('accounts')->where('id', $account->id)->update([
        'expiry' => '2026-10-10 17:30:00', 'hotspot_pending_validity' => null, 'hotspot_first_login_at' => now(),
    ]);

    expect(fn () => app(RechargeAccount::class)->handle($account->id, Plan::factory()->hours(3)->firstLogin()->create()->id, null, '20', null, $key))
        ->toThrow(ExpiryChangedMeanwhile::class);
});

it('records the kind and logs a change of plan as plan-changed', function () {
    $account = Account::factory()->create(['plan_id' => Plan::factory()->create(['name' => 'Home 25'])->id, 'expiry' => '2026-10-20 10:00:00']);
    $plan = Plan::factory()->create(['name' => 'Home 100']);

    $recharge = app(RechargeAccount::class)->handle($account->id, $plan->id, Carbon::parse('2026-11-09 14:30'), '999', 'More speed', null, RechargeKind::ChangePlan);

    $entry = Activity::query()->where('subject_id', $account->id)->where('event', '!=', 'created')->sole();
    expect($recharge->fresh()->kind)->toBe(RechargeKind::ChangePlan)
        ->and($entry->event)->toBe('plan-changed')
        ->and($entry->attribute_changes->toArray())->toEqual([
            'old' => ['plan' => 'Home 25', 'expiry' => '2026-10-20 10:00'],
            'attributes' => ['plan' => 'Home 100', 'expiry' => '2026-11-09 14:30', 'amount' => '999', 'note' => 'More speed'],
        ]);
});

it('records the staff member who booked it, with System as the causer when nobody is logged in', function () {
    auth()->logout();
    $account = Account::factory()->create(['expiry' => '2026-10-20 10:00:00']);

    $recharge = app(RechargeAccount::class)->handle($account->id, Plan::factory()->create()->id, Carbon::parse('2026-11-19 10:00'), '599', null, null, RechargeKind::Advance, $this->staff->id);

    $entry = Activity::query()->where('subject_id', $account->id)->where('event', '!=', 'created')->sole();
    expect($recharge->user_id)->toBe($this->staff->id)
        ->and($recharge->fresh()->kind)->toBe(RechargeKind::Advance)
        ->and($entry->event)->toBe('advance-started')
        ->and($entry->causer_id)->toBeNull();
});

it('moves a waiting renewal when a recharge extends the expiry', function () {
    $account = Account::factory()->create(['expiry' => '2026-10-31 00:00:00']);
    $waiting = AdvanceRenewal::factory()->for($account)->create(['starts_at' => '2026-10-31 00:00:00', 'expiry' => '2026-11-30 00:00:00']);

    app(RechargeAccount::class)->handle($account->id, Plan::factory()->create()->id, Carbon::parse('2026-11-30 00:00'), '599', null);

    $moved = Activity::query()->where('subject_id', $account->id)->where('event', 'advance-moved')->sole();
    expect($waiting->fresh())
        ->starts_at->format('Y-m-d H:i')->toBe('2026-11-30 00:00')
        ->expiry->format('Y-m-d H:i')->toBe('2026-12-30 00:00')
        ->and($moved->attribute_changes->toArray())->toEqual([
            'old' => ['starts' => '2026-10-31 00:00'],
            'attributes' => ['starts' => '2026-11-30 00:00'],
        ]);
});

it('leaves the waiting renewal alone when it is the one starting', function () {
    $account = Account::factory()->create(['expiry' => '2026-10-31 00:00:00']);
    $waiting = AdvanceRenewal::factory()->for($account)->create(['starts_at' => '2026-10-31 00:00:00', 'expiry' => '2026-11-30 00:00:00']);

    app(RechargeAccount::class)->handle($account->id, $waiting->plan_id, Carbon::parse('2026-11-30 00:00'), '599', null, null, RechargeKind::Advance);

    expect($waiting->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-10-31 00:00');
});

it('keeps the six-argument call of older add-ons working', function () {
    $account = Account::factory()->create(['expiry' => '2026-10-20 10:00:00']);

    $recharge = app(RechargeAccount::class)->handle($account->id, Plan::factory()->create()->id, Carbon::parse('2026-11-19 10:00'), '599', null, '2026-10-20 10:00:00');

    expect($recharge->fresh()->kind)->toBe(RechargeKind::Recharge)
        ->and($recharge->user_id)->toBe($this->staff->id)
        ->and(Activity::query()->where('subject_id', $account->id)->where('event', '!=', 'created')->sole()->event)->toBe('recharged');
});
