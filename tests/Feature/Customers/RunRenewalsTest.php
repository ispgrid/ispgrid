<?php

use App\Actions\Customers\RechargeAccount;
use App\Enums\AccountStatus;
use App\Enums\AdvanceRenewalStatus;
use App\Enums\RechargeKind;
use App\Exceptions\ExpiryChangedMeanwhile;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-31 23:50:00'));
    $this->plan = Plan::factory()->create(['name' => 'Home 25', 'validity' => 30, 'price' => 599]);
});

function runRenewals(): void
{
    test()->artisan('customers:renewals')->assertSuccessful();
}

function eventsOf(Account $account, string $event): int
{
    return Activity::query()->where('subject_id', $account->id)->where('event', $event)->count();
}

it('starts a waiting renewal 10 minutes before the expiry and not 11', function () {
    $booker = User::factory()->create();
    $soon = Account::factory()->create(['expiry' => '2026-11-01 00:00:00']);
    $later = Account::factory()->create(['expiry' => '2026-11-01 00:01:00']);
    $other = Plan::factory()->create(['name' => 'Home 100']);
    $renewal = AdvanceRenewal::factory()->for($soon)->create([
        'plan_id' => $other->id, 'amount' => 999, 'user_id' => $booker->id,
        'starts_at' => '2026-11-01 00:00:00', 'expiry' => '2026-12-01 00:00:00',
    ]);
    $waits = AdvanceRenewal::factory()->for($later)->create(['starts_at' => '2026-11-01 00:01:00', 'expiry' => '2026-12-01 00:01:00']);

    runRenewals();

    $recharge = Recharge::query()->where('account_id', $soon->id)->sole();
    expect($soon->fresh())
        ->plan_id->toBe($other->id)
        ->expiry->format('Y-m-d H:i')->toBe('2026-12-01 00:00')
        ->and($recharge)
        ->kind->toBe(RechargeKind::Advance)
        ->amount->toBe('999.00')
        ->user_id->toBe($booker->id)
        ->and($renewal->fresh())
        ->status->toBe(AdvanceRenewalStatus::Started)
        ->recharge_id->toBe($recharge->id)
        ->and($waits->fresh()->status)->toBe(AdvanceRenewalStatus::Waiting)
        ->and(Activity::query()->where('subject_id', $soon->id)->where('event', 'advance-started')->sole()->causer_id)->toBeNull();
});

it('auto renews at the plan price from the old expiry', function () {
    $account = Account::factory()->create(['plan_id' => $this->plan->id, 'auto_renew' => true, 'expiry' => '2026-11-01 00:00:00']);

    runRenewals();

    $recharge = Recharge::query()->sole();
    expect($account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-12-01 00:00')
        ->and($recharge->kind)->toBe(RechargeKind::Auto)
        ->and($recharge->amount)->toBe('599.00')
        ->and($recharge->user_id)->toBeNull()
        ->and(eventsOf($account, 'auto-renewed'))->toBe(1);
});

it('renews a late account from its old expiry', function () {
    $account = Account::factory()->create(['plan_id' => $this->plan->id, 'auto_renew' => true, 'expiry' => '2026-10-31 20:50:00']);

    runRenewals();

    expect($account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-11-30 20:50');
});

it('uses midnight mode for an auto renewal', function () {
    AppSetting::put('expiry_mode', 'midnight');
    $account = Account::factory()->create(['plan_id' => $this->plan->id, 'auto_renew' => true, 'expiry' => '2026-10-31 23:55:00']);

    runRenewals();

    expect($account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-12-01 00:00');
});

it('renews once when the command runs twice', function () {
    Account::factory()->create(['plan_id' => $this->plan->id, 'auto_renew' => true, 'expiry' => '2026-11-01 00:00:00']);

    runRenewals();
    runRenewals();

    expect(Recharge::query()->count())->toBe(1);
});

it('skips an account whose expiry changed meanwhile', function () {
    $account = Account::factory()->create(['plan_id' => $this->plan->id, 'auto_renew' => true, 'expiry' => '2026-11-01 00:00:00']);
    $read = $account->fresh();
    DB::table('accounts')->where('id', $account->id)->update(['expiry' => '2026-11-15 00:00:00']);

    expect(fn () => app(RechargeAccount::class)->handle($account->id, $this->plan->id, Carbon::parse('2026-12-01 00:00'), '599', null, RechargeAccount::expiryKey($read), RechargeKind::Auto))
        ->toThrow(ExpiryChangedMeanwhile::class);

    runRenewals();

    expect(Recharge::query()->count())->toBe(0)
        ->and($account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-11-15 00:00');
});

it('skips and logs once a customer who cannot be auto renewed', function (Closure $make, string $reason) {
    $account = $make($this->plan);

    runRenewals();
    runRenewals();

    $skipped = Activity::query()->where('subject_id', $account->id)->where('event', 'auto-renew-skipped')->sole();
    expect(Recharge::query()->count())->toBe(0)
        ->and($skipped->attribute_changes['attributes'])->toEqual(['reason' => $reason, 'expiry' => $account->expiry->format('Y-m-d H:i')]);
})->with([
    'suspended' => [fn (Plan $plan) => Account::factory()->suspended()->create(['plan_id' => $plan->id, 'auto_renew' => true, 'expiry' => '2026-11-01 00:00:00']), 'The customer is Suspended'],
    'no plan' => [fn (Plan $plan) => Account::factory()->create(['plan_id' => null, 'auto_renew' => true, 'expiry' => '2026-11-01 00:00:00']), 'The customer has no plan'],
    'deleted plan' => [function (Plan $plan) {
        $account = Account::factory()->create(['plan_id' => $plan->id, 'auto_renew' => true, 'expiry' => '2026-11-01 00:00:00']);
        $plan->delete();

        return $account;
    }, 'The plan Home 25 was deleted'],
    'expired too long ago' => [fn (Plan $plan) => Account::factory()->create(['plan_id' => $plan->id, 'auto_renew' => true, 'expiry' => '2026-09-01 00:00:00']), 'The plan ended too long ago; recharge by hand'],
]);

it('checks customers it already skipped with a few queries, not a few per customer', function () {
    Account::factory()->suspended()->count(30)->create(['plan_id' => $this->plan->id, 'auto_renew' => true, 'expiry' => '2026-10-31 20:00:00']);
    Account::factory()->count(5)->create(['plan_id' => $this->plan->id, 'expiry' => '2026-10-31 20:00:00']);
    runRenewals();

    DB::enableQueryLog();
    runRenewals();
    $queries = collect(DB::getQueryLog())->pluck('query');

    expect(Activity::query()->where('event', 'auto-renew-skipped')->count())->toBe(30)
        ->and(Activity::query()->where('event', 'expired')->count())->toBe(5)
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'activity_log'))->count())->toBeLessThanOrEqual(2)
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'from `plans`'))->count())->toBeLessThanOrEqual(2);
});

it('skips a deleted customer without logging', function () {
    $account = Account::factory()->create(['plan_id' => $this->plan->id, 'auto_renew' => true, 'expiry' => '2026-11-01 00:00:00']);
    $account->delete();

    runRenewals();

    expect(Recharge::query()->count())->toBe(0)->and(eventsOf($account, 'auto-renew-skipped'))->toBe(0);
});

it('starts a waiting renewal for a suspended customer and keeps the status', function () {
    $account = Account::factory()->suspended()->create(['expiry' => '2026-11-01 00:00:00']);
    $renewal = AdvanceRenewal::factory()->for($account)->create(['starts_at' => '2026-11-01 00:00:00', 'expiry' => '2026-12-01 00:00:00']);

    runRenewals();

    expect($renewal->fresh()->status)->toBe(AdvanceRenewalStatus::Started)
        ->and($account->fresh()->status_id)->toBe(AccountStatus::Suspended)
        ->and($account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-12-01 00:00');
});

it('cancels a waiting renewal whose plan was deleted, and logs why', function () {
    $account = Account::factory()->create(['expiry' => '2026-11-01 00:00:00']);
    $renewal = AdvanceRenewal::factory()->for($account)->create(['plan_id' => $this->plan->id, 'starts_at' => '2026-11-01 00:00:00']);
    $this->plan->delete();

    runRenewals();

    expect($renewal->fresh()->status)->toBe(AdvanceRenewalStatus::Cancelled)
        ->and(Recharge::query()->count())->toBe(0)
        ->and(Activity::query()->where('subject_id', $account->id)->where('event', 'auto-renew-skipped')->sole()->attribute_changes['attributes']['reason'])
        ->toBe('The plan Home 25 was deleted; the advance renewal is cancelled');
});

it('ends an override speed at its until time and disconnects the customer', function () {
    Queue::fake();
    $ended = Account::factory()->create(['username' => 'alice', 'override_plan' => true, 'override_upload' => '25M', 'override_download' => '70M', 'override_until' => '2026-10-31 23:50:00']);
    $runs = Account::factory()->create(['override_plan' => true, 'override_upload' => '25M', 'override_download' => '70M', 'override_until' => '2026-10-31 23:51:00']);
    DB::table('radacct')->insert(['acctsessionid' => 's1', 'acctuniqueid' => 'u1', 'username' => 'alice', 'nasipaddress' => '10.0.0.1', 'acctstarttime' => now()]);

    runRenewals();

    expect($ended->fresh()->override_plan)->toBeFalse()
        ->and(eventsOf($ended, 'own-speed-ended'))->toBe(1)
        ->and($runs->fresh()->override_plan)->toBeTrue();
    Queue::assertPushed(DisconnectAccount::class, 1);
});

it('logs a plan that expired once, only for an Active customer nothing renewed', function () {
    $expired = Account::factory()->create(['plan_id' => $this->plan->id, 'expiry' => '2026-10-31 23:00:00']);
    $suspended = Account::factory()->suspended()->create(['expiry' => '2026-10-31 23:00:00']);
    $running = Account::factory()->create(['expiry' => '2026-11-02 00:00:00']);

    runRenewals();
    runRenewals();

    expect(Activity::query()->where('subject_id', $expired->id)->where('event', 'expired')->sole()->attribute_changes['attributes'])
        ->toEqual(['plan' => 'Home 25', 'expiry' => '2026-10-31 23:00'])
        ->and(eventsOf($suspended, 'expired'))->toBe(0)
        ->and(eventsOf($running, 'expired'))->toBe(0);
});

it('is scheduled every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'customers:renewals'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
