<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\NotificationLog;
use App\Models\Plan;
use App\Models\Radacct;
use App\Models\Radpostauth;
use App\Models\Recharge;
use App\Models\User;
use App\Support\Dashboard\NeedsAttention;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
    $this->actingAs(User::factory()->create());
});

function attention(?User $user = null): NeedsAttention
{
    return new NeedsAttention($user ?? User::factory()->create());
}

it('warns about each router too old to send Message-Authenticator', function () {
    Nas::factory()->legacy()->create(['shortname' => 'old-tower', 'nasname' => '10.0.0.7']);
    Nas::factory()->create(['shortname' => 'new-core']);
    Nas::factory()->legacy()->trashed()->create(['shortname' => 'gone']);

    Livewire::test('pages::dashboard')
        ->assertSee('old-tower (10.0.0.7)')
        ->assertSee('RouterOS 6.49.17 is affected by the Blast-RADIUS security issue')
        ->assertDontSee('new-core')
        ->assertDontSee('gone');
});

it('shows no warning when every router is current', function () {
    Nas::factory()->create();

    Livewire::test('pages::dashboard')->assertDontSee('Blast-RADIUS');
});

it('lists the usernames refused 10 times or more today, the most first, at most 5', function () {
    Radpostauth::factory()->rejected()->count(9)->create(['username' => 'nine']);
    Radpostauth::factory()->rejected()->count(10)->create(['username' => 'ten']);
    Radpostauth::factory()->rejected()->count(12)->create(['username' => 'twelve']);
    Radpostauth::factory()->rejected()->count(20)->create(['username' => 'yesterday', 'authdate' => '2026-10-19 23:59:00']);
    Radpostauth::factory()->count(30)->create(['username' => 'accepted']);
    foreach (range(1, 4) as $n) {
        Radpostauth::factory()->dropped()->count(11)->create(['username' => "dropped{$n}"]);
    }

    $looping = attention()->loopingLogins();

    expect(array_column($looping, 'username'))->toBe(['twelve', 'dropped1', 'dropped2', 'dropped3', 'dropped4'])
        ->and($looping[0]['count'])->toBe(12)
        ->and($looping[0]['href'])->toBe(route('login-log.index', ['username' => 'twelve']))
        ->and(attention(staffHolding(Permission::ViewPlans))->loopingLogins()[0]['href'])->toBeNull();
});

it('names a router that had sessions in the last day and has been quiet for over 30 minutes', function () {
    Nas::factory()->create(['shortname' => 'quiet31', 'nasname' => '10.9.0.31']);
    Nas::factory()->create(['shortname' => 'quiet29', 'nasname' => '10.9.0.29']);
    Nas::factory()->create(['shortname' => 'idle', 'nasname' => '10.9.0.50']);
    Nas::factory()->create(['shortname' => 'talking', 'nasname' => '10.9.0.60']);
    Radacct::factory()->create(['nasipaddress' => '10.9.0.31', 'acctstarttime' => '2026-10-20 08:00:00', 'acctupdatetime' => '2026-10-20 11:29:00']);
    Radacct::factory()->create(['nasipaddress' => '10.9.0.29', 'acctstarttime' => '2026-10-20 08:00:00', 'acctupdatetime' => '2026-10-20 11:31:00']);
    Radacct::factory()->create(['nasipaddress' => '10.9.0.50', 'acctstarttime' => '2026-10-18 08:00:00', 'acctupdatetime' => '2026-10-18 09:00:00', 'acctstoptime' => '2026-10-18 09:00:00']);
    Radacct::factory()->create(['nasipaddress' => '10.9.0.60', 'acctstarttime' => '2026-10-20 08:00:00', 'acctupdatetime' => '2026-10-20 08:00:00']);
    Radpostauth::factory()->create(['nasipaddress' => '10.9.0.60', 'authdate' => '2026-10-20 11:50:00']);

    expect(attention()->quietRouters())->toBe([['router' => 'quiet31 (10.9.0.31)', 'last_heard' => '20/10/2026 11:29']]);
});

it('keeps naming a NAS while its sessions stay open, from indexed lookups only', function () {
    Nas::factory()->create(['shortname' => 'dead', 'nasname' => '10.9.0.70']);
    Radacct::factory()->create(['nasipaddress' => '10.9.0.70', 'acctstarttime' => '2026-10-17 08:00:00', 'acctupdatetime' => '2026-10-17 09:00:00']);

    DB::enableQueryLog();
    $quiet = attention()->quietRouters();
    $radacct = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'radacct'));

    expect($quiet)->toBe([['router' => 'dead (10.9.0.70)', 'last_heard' => '17/10/2026 09:00']])
        ->and($radacct->filter(fn (string $query): bool => str_contains(strtolower($query), ' or ') || str_contains($query, 'acctupdatetime` >')))->toBeEmpty();
});

it('counts the messages that failed today', function () {
    NotificationLog::factory()->failed()->count(2)->create();
    NotificationLog::factory()->failed()->create(['created_at' => '2026-10-19 23:00:00']);
    NotificationLog::factory()->sent()->create();

    expect(attention()->failedMessages())->toBe(2);
});

it('lists a failed disconnect from the last day while the session is still open', function () {
    $stuck = Account::factory()->create(['username' => 'stuck']);
    $gone = Account::factory()->create(['username' => 'gone']);
    $old = Account::factory()->create(['username' => 'old']);
    Radacct::factory()->create(['username' => 'stuck']);
    Radacct::factory()->closed()->create(['username' => 'gone']);
    Radacct::factory()->create(['username' => 'old']);
    foreach ([[$stuck, '2026-10-20 11:00:00'], [$gone, '2026-10-20 11:00:00'], [$old, '2026-10-19 11:59:00']] as [$account, $when]) {
        activity()->performedOn($account)->event('disconnect-failed')
            ->withChanges(['old' => [], 'attributes' => ['reason' => 'No answer from 10.0.0.1']])->createdAt(Carbon::parse($when))->log('disconnect-failed');
    }

    expect(attention()->failedDisconnects())->toBe([
        ['username' => 'stuck', 'reason' => 'No answer from 10.0.0.1', 'href' => route('customers.show', $stuck)],
    ]);
});

it('asks the session table only about the customers whose disconnect failed', function () {
    Radacct::factory()->count(3)->create();
    activity()->performedOn(Account::factory()->create(['username' => 'stuck']))->event('disconnect-failed')
        ->withChanges(['old' => [], 'attributes' => ['reason' => 'No answer']])->log('disconnect-failed');

    DB::enableQueryLog();
    attention()->failedDisconnects();

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'radacct'))->pluck('bindings')->flatten()->all())
        ->toContain('stuck');
});

it('lists an auto renewal skipped in the last 7 days until the customer is recharged', function () {
    $waiting = Account::factory()->create(['username' => 'waiting']);
    $recharged = Account::factory()->create(['username' => 'recharged']);
    $old = Account::factory()->create(['username' => 'old']);
    foreach ([[$waiting, '2026-10-19 00:00:00'], [$recharged, '2026-10-19 00:00:00'], [$old, '2026-10-13 11:59:00']] as [$account, $when]) {
        activity()->performedOn($account)->event('auto-renew-skipped')
            ->withChanges(['old' => [], 'attributes' => ['reason' => 'The customer has no plan', 'expiry' => '2026-10-19 00:00']])
            ->createdAt(Carbon::parse($when))->log('auto-renew-skipped');
    }
    Recharge::factory()->for($recharged)->create(['created_at' => '2026-10-19 10:00:00']);

    expect(attention()->skippedRenewals())->toBe([
        ['username' => 'waiting', 'reason' => 'The customer has no plan', 'href' => route('customers.show', $waiting)],
    ]);
});

it('lists Active customers at or over their monthly data limit, from one query for the usage', function () {
    $thisMonth = now()->startOfMonth()->toDateString();
    $lastMonth = now()->startOfMonth()->subDay()->toDateString();
    $plan = Plan::factory()->create(['monthly_data_gb' => 10]);
    Account::factory()->create(['username' => 'heavy', 'plan_id' => $plan->id]);
    Account::factory()->create(['username' => 'light', 'plan_id' => $plan->id]);
    Account::factory()->suspended()->create(['username' => 'paused', 'plan_id' => $plan->id]);
    Account::factory()->create(['username' => 'unlimited', 'plan_id' => Plan::factory()->create()->id]);
    $gigabyte = 1_073_741_824;
    foreach (['heavy' => 10 * $gigabyte, 'light' => 10 * $gigabyte - 1, 'paused' => 20 * $gigabyte, 'unlimited' => 20 * $gigabyte] as $username => $bytes) {
        usageDay($username, $thisMonth, $bytes);
    }
    usageDay('light', $lastMonth, $gigabyte);

    DB::enableQueryLog();
    $over = attention()->overDataLimit();
    $usageQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'usage_daily'))->count();

    expect(array_column($over, 'username'))->toBe(['heavy'])
        ->and($over[0]['used'])->toBe('10.0 GB')
        ->and($over[0]['limit'])->toBe('10.0 GB')
        ->and($usageQueries)->toBe(1);
});

it('lists IP ranges with 90 percent or more of their addresses given out', function (int $given, bool $listed) {
    $range = IpRange::factory()->pppoe()->create(['name' => 'Static', 'network' => '10.7.0.1-10.7.0.100']);
    foreach (range(1, 100) as $n) {
        Ip::factory()->create(['ip_range_id' => $range->id, 'address' => "10.7.0.{$n}", 'is_used' => $n <= $given]);
    }

    expect(attention()->fullRanges())->toBe($listed ? [['range' => 'Static (10.7.0.1-10.7.0.100)', 'used' => $given, 'total' => 100]] : []);
})->with([
    '89 percent' => [89, false],
    '90 percent' => [90, true],
]);

it('reports the backup age and the queue only to staff allowed App settings', function (?int $age, bool $late) {
    if ($age !== null) {
        AppSetting::put('backup.last_finished_at', now()->subHours($age)->subMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z'));
    }
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
    DB::table('failed_jobs')->insert(['uuid' => 'f1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x']);

    expect(attention()->server())->toBe(['backup_age_hours' => $age, 'backup_late' => $late, 'jobs_waiting' => 1, 'jobs_failed' => 1])
        ->and(attention(staffHolding(Permission::ViewCustomers))->server())->toBeNull();
})->with([
    '25 hours' => [25, false],
    '27 hours' => [27, true],
    'never' => [null, true],
]);

it('shows what needs attention on the dashboard and hides the empty parts', function () {
    Radpostauth::factory()->rejected()->count(10)->create(['username' => 'looper']);

    Livewire::test('pages::dashboard')
        ->assertSee('Logins stuck in a loop')
        ->assertSee('looper')
        ->assertDontSee('IP ranges almost full')
        ->assertSee('Last backup');
});

it('links each customer named under needs attention', function () {
    $account = Account::factory()->create(['username' => 'skipped_one']);
    activity()->performedOn($account)->event('auto-renew-skipped')
        ->withChanges(['old' => [], 'attributes' => ['reason' => 'The customer has no plan', 'expiry' => '2026-10-19 00:00']])->log('auto-renew-skipped');

    Livewire::test('pages::dashboard')
        ->assertSee('Auto renewals that did not happen')
        ->assertSee('The customer has no plan')
        ->assertSeeHtml('href="'.route('customers.show', $account).'"');
});
