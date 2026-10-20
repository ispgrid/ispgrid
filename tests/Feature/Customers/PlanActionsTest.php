<?php

use App\Actions\Customers\BookAdvanceRenewal;
use App\Actions\Customers\CancelAdvanceRenewal;
use App\Actions\Customers\ChangeExpiry;
use App\Actions\Customers\SetOwnSpeed;
use App\Actions\Customers\TerminateAccount;
use App\Enums\AccountStatus;
use App\Enums\AdvanceRenewalStatus;
use App\Exceptions\RenewalAlreadyWaiting;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 14:30:00'));
    $this->staff = User::factory()->create();
    $this->actingAs($this->staff);
});

/**
 * The one entry an action logged (the factory's "created" entry left out).
 */
function planActionEntry(Account $account): Activity
{
    return Activity::query()->where('subject_id', $account->id)->where('event', '!=', 'created')->sole();
}

function radiusRows(string $table, string $username): array
{
    return DB::table($table)->where('username', $username)->pluck('value', 'attribute')->all();
}

it('books an advance renewal that starts at the expiry', function () {
    $account = Account::factory()->create(['expiry' => '2026-10-31 00:00:00']);
    $plan = Plan::factory()->create(['name' => 'Home 100']);

    $renewal = app(BookAdvanceRenewal::class)->handle($account->id, $plan->id, Carbon::parse('2026-11-30 00:00'), '999', 'Paid in advance');

    expect($renewal->fresh())
        ->status->toBe(AdvanceRenewalStatus::Waiting)
        ->starts_at->format('Y-m-d H:i')->toBe('2026-10-31 00:00')
        ->expiry->format('Y-m-d H:i')->toBe('2026-11-30 00:00')
        ->amount->toBe('999.00')
        ->user_id->toBe($this->staff->id)
        ->and($account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-10-31 00:00')
        ->and(planActionEntry($account))
        ->event->toBe('advance-booked')
        ->and(planActionEntry($account)->attribute_changes['attributes'])->toEqual([
            'plan' => 'Home 100', 'starts' => '2026-10-31 00:00', 'expiry' => '2026-11-30 00:00', 'amount' => '999', 'note' => 'Paid in advance',
        ]);
});

it('refuses a second advance renewal while one waits', function () {
    $account = Account::factory()->create(['expiry' => '2026-10-31 00:00:00']);
    AdvanceRenewal::factory()->for($account)->create();

    expect(fn () => app(BookAdvanceRenewal::class)->handle($account->id, Plan::factory()->create()->id, Carbon::parse('2026-11-30 00:00'), '999', null))
        ->toThrow(RenewalAlreadyWaiting::class);
    expect(AdvanceRenewal::query()->count())->toBe(1);
});

it('refuses an advance renewal without a running period or with a first-login plan', function (Closure $account, Closure $plan) {
    expect(fn () => app(BookAdvanceRenewal::class)->handle($account()->id, $plan()->id, Carbon::parse('2026-11-30 00:00'), '999', null))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'no plan' => [fn () => Account::factory()->create(['plan_id' => null]), fn () => Plan::factory()->create()],
    'expired' => [fn () => Account::factory()->create(['expiry' => '2026-10-01 00:00:00']), fn () => Plan::factory()->create()],
    'no expiry' => [fn () => Account::factory()->create(['expiry' => null]), fn () => Plan::factory()->create()],
    'first-login plan' => [fn () => Account::factory()->hotspot()->create(), fn () => Plan::factory()->hours(3)->firstLogin()->create()],
]);

it('cancels a waiting advance renewal, and does nothing when none waits', function () {
    $account = Account::factory()->create();
    $renewal = AdvanceRenewal::factory()->for($account)->create();

    app(CancelAdvanceRenewal::class)->handle($account->id);
    app(CancelAdvanceRenewal::class)->handle($account->id);

    expect($renewal->fresh()->status)->toBe(AdvanceRenewalStatus::Cancelled)
        ->and(planActionEntry($account)->event)->toBe('advance-cancelled');
});

it('changes the expiry and moves a waiting renewal', function () {
    $account = Account::factory()->create(['username' => 'alice', 'expiry' => '2026-10-31 00:00:00']);
    $renewal = AdvanceRenewal::factory()->for($account)->create(['starts_at' => '2026-10-31 00:00:00', 'expiry' => '2026-11-30 00:00:00']);

    $disconnect = app(ChangeExpiry::class)->handle($account->id, Carbon::parse('2026-11-05 00:00'), 'Down 5 days');

    $entry = Activity::query()->where('subject_id', $account->id)->where('event', 'expiry-changed')->sole();
    expect($disconnect)->toBeFalse()
        ->and($account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-11-05 00:00')
        ->and(radiusRows('radcheck', 'alice')['Expiration'])->toBe('05 Nov 2026 00:00')
        ->and($renewal->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-11-05 00:00')
        ->and($entry->attribute_changes->toArray())->toEqual([
            'old' => ['expiry' => '2026-10-31 00:00'],
            'attributes' => ['expiry' => '2026-11-05 00:00', 'note' => 'Down 5 days'],
        ]);
});

it('asks for a disconnect only when the new expiry is less than a minute away', function (string $expiry, bool $disconnect) {
    $account = Account::factory()->create(['expiry' => '2026-10-31 00:00:00']);

    expect(app(ChangeExpiry::class)->handle($account->id, Carbon::parse($expiry), null))->toBe($disconnect);
})->with([
    '30 seconds ahead' => ['2026-10-10 14:30:30', true],
    'in the past' => ['2026-10-09 14:30:00', true],
    'a day ahead' => ['2026-10-11 14:30:00', false],
]);

it('sets an own speed until a date, keeping the other custom speed fields', function () {
    $account = Account::factory()->create(['username' => 'alice', 'override_priority' => 5]);

    app(SetOwnSpeed::class)->handle($account->id, '25M', '70M', Carbon::parse('2026-11-01 00:00'), 'Trial');

    expect($account->fresh())
        ->override_plan->toBeTrue()
        ->override_upload->toBe('25M')
        ->override_download->toBe('70M')
        ->override_priority->toBe(5)
        ->override_until->format('Y-m-d H:i')->toBe('2026-11-01 00:00')
        ->and(radiusRows('radreply', 'alice'))->toHaveKey('Mikrotik-Rate-Limit')
        ->and(planActionEntry($account)->event)->toBe('own-speed-set')
        ->and(planActionEntry($account)->attribute_changes['attributes'])->toEqual(['speed' => '25M/70M', 'until' => '2026-11-01 00:00', 'note' => 'Trial']);
});

it('removes an own speed and every custom speed field', function () {
    $account = Account::factory()->create([
        'username' => 'alice', 'override_plan' => true, 'override_upload' => '25M', 'override_download' => '70M',
        'override_priority' => 5, 'override_until' => '2026-11-01 00:00:00',
    ]);

    app(SetOwnSpeed::class)->handle($account->id, null, null, null, null);

    expect($account->fresh())
        ->override_plan->toBeFalse()
        ->override_upload->toBeNull()
        ->override_priority->toBeNull()
        ->override_until->toBeNull()
        ->and(radiusRows('radreply', 'alice'))->not->toHaveKey('Mikrotik-Rate-Limit')
        ->and(planActionEntry($account)->event)->toBe('own-speed-removed')
        ->and(planActionEntry($account)->attribute_changes['old'])->toEqual(['speed' => '25M/70M']);
});

it('refuses an own speed MikroTik would not read', function () {
    $account = Account::factory()->create();

    expect(fn () => app(SetOwnSpeed::class)->handle($account->id, '25 Mbps', '70M', null, null))
        ->toThrow(InvalidArgumentException::class);
});

it('terminates a customer: no plan, no auto renew, no waiting renewal', function () {
    $plan = Plan::factory()->create(['name' => 'Home 25']);
    $account = Account::factory()->create(['username' => 'alice', 'plan_id' => $plan->id, 'auto_renew' => true, 'expiry' => '2026-10-31 00:00:00']);
    $renewal = AdvanceRenewal::factory()->for($account)->create();

    app(TerminateAccount::class)->handle($account->id, 'Moved away');

    $entry = Activity::query()->where('subject_id', $account->id)->where('event', 'terminated')->sole();
    expect($account->fresh())
        ->status_id->toBe(AccountStatus::Terminated)
        ->plan_id->toBeNull()
        ->auto_renew->toBeFalse()
        ->expiry->format('Y-m-d H:i')->toBe('2026-10-31 00:00')
        ->and($renewal->fresh()->status)->toBe(AdvanceRenewalStatus::Cancelled)
        ->and(radiusRows('radcheck', 'alice')['Acct-Status-Id'])->toBe('4')
        ->and(DB::table('radusergroup')->where('username', 'alice')->exists())->toBeFalse()
        ->and($entry->attribute_changes->toArray())->toEqual([
            'old' => ['status' => 'Active', 'plan' => 'Home 25'],
            'attributes' => ['status' => 'Terminated', 'note' => 'Moved away'],
        ]);
});
