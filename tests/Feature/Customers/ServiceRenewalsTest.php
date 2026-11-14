<?php

use App\Actions\Customers\AddCustomerService;
use App\Actions\Customers\ReleaseServiceIp;
use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Enums\DisconnectReason;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\CustomerService;
use App\Models\Ip;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-14 10:00:00'));
    $this->actingAs(User::factory()->create());
    Queue::fake();
    $this->ip = Ip::factory()->create(['address' => '10.0.0.5']);
    $this->account = Account::factory()->create(['username' => 'alice']);
    $this->service = app(AddCustomerService::class)->handle($this->account, Item::factory()->staticIp()->create(), 1, now(), '300', '10.0.0.5', null);
});

function serviceRenewals(): void
{
    Artisan::call('customers:renewals');
}

function online(string $username): void
{
    DB::table('radacct')->insert(['acctsessionid' => 's-'.$username, 'acctuniqueid' => 'u-'.$username, 'username' => $username, 'nasipaddress' => '10.0.0.1', 'acctstarttime' => now()]);
}

function staticIpSent(Account $account): bool
{
    return DB::table('radreply')->where('username', $account->username)->where('attribute', 'Framed-IP-Address')->exists();
}

it('lapses a static ip once and disconnects', function () {
    online('alice');
    $this->travelTo(Carbon::parse('2026-12-14 10:00:30'));

    serviceRenewals();
    serviceRenewals();

    $service = $this->service->fresh();
    Queue::assertPushed(DisconnectAccount::class, 1);
    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->reason === DisconnectReason::StaticIpExpired);
    expect($service->expired_at)->not->toBeNull()
        ->and($service->held_until->toDateTimeString())->toBe('2027-01-13 10:00:00')
        ->and($this->ip->fresh()->is_used)->toBeTrue()
        ->and($this->account->fresh()->ip_id)->toBe($this->ip->id)
        ->and(staticIpSent($this->account))->toBeFalse()
        ->and(DB::table('activity_log')->where('event', 'service-expired')->count())->toBe(1);
});

it('catches up once when the scheduler was down for hours', function () {
    online('alice');
    $this->travelTo(Carbon::parse('2026-12-14 18:00:00'));

    serviceRenewals();
    serviceRenewals();

    Queue::assertPushed(DisconnectAccount::class, 1);
    expect($this->service->fresh()->held_until->toDateTimeString())->toBe('2027-01-13 10:00:00');
});

it('holds until staff release when the setting is 0', function () {
    AppSetting::put('billing.static_ip_hold_days', '0');
    $this->travelTo(Carbon::parse('2026-12-14 10:01:00'));
    serviceRenewals();

    expect($this->service->fresh()->held_until)->toBeNull()
        ->and($this->service->fresh()->expired_at)->not->toBeNull();

    $this->travel(400)->days();
    serviceRenewals();

    expect($this->service->fresh()->ended_at)->toBeNull()
        ->and($this->account->fresh()->ip_id)->toBe($this->ip->id);
});

it('releases the ip after the hold', function () {
    $this->travelTo(Carbon::parse('2026-12-14 10:01:00'));
    serviceRenewals();
    Queue::fake();
    online('alice');

    $this->travelTo(Carbon::parse('2027-01-13 10:01:00'));
    serviceRenewals();

    $service = $this->service->fresh();
    expect($service->ended_at)->not->toBeNull()
        ->and($service->end_reason)->toBe('Hold ended')
        ->and($this->account->fresh()->ip_id)->toBeNull()
        ->and($this->ip->fresh()->is_used)->toBeFalse()
        ->and(DB::table('activity_log')->where('event', 'service-ip-released')->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('keeps the hold fixed when the setting changes later', function () {
    $this->travelTo(Carbon::parse('2026-12-14 10:01:00'));
    serviceRenewals();
    AppSetting::put('billing.static_ip_hold_days', '2');

    $this->travelTo(Carbon::parse('2026-12-20 10:00:00'));
    serviceRenewals();

    expect($this->service->fresh()->ended_at)->toBeNull()
        ->and($this->service->fresh()->held_until->toDateTimeString())->toBe('2027-01-13 10:00:00');
});

it('lapses a plain service without touching radius', function () {
    $plain = CustomerService::factory()->for($this->account)->create(['paid_until' => now()->addMinute()]);
    $rows = DB::table('radreply')->where('username', 'alice')->count();
    online('alice');

    $this->travelTo(Carbon::parse('2026-11-14 10:05:00'));
    serviceRenewals();

    expect($plain->fresh()->expired_at)->not->toBeNull()
        ->and($plain->fresh()->held_until)->toBeNull()
        ->and($plain->fresh()->ended_at)->toBeNull()
        ->and(DB::table('radreply')->where('username', 'alice')->count())->toBe($rows);
    Queue::assertNothingPushed();
});

it('leaves the service alone when the plan expires or the customer is suspended', function () {
    $this->account->update(['expiry' => now()->subDay(), 'status_id' => AccountStatus::Suspended]);

    serviceRenewals();

    expect($this->service->fresh()->expired_at)->toBeNull()
        ->and($this->account->fresh()->ip_id)->toBe($this->ip->id)
        ->and($this->ip->fresh()->is_used)->toBeTrue();
});

it('drops the fixed ip at the first sync after paid until, before the scheduler marks it', function () {
    $this->travelTo(Carbon::parse('2026-12-14 10:00:30'));

    app(SyncAccountToRadius::class)->handle($this->account->fresh());

    expect(staticIpSent($this->account))->toBeFalse()
        ->and($this->service->fresh()->expired_at)->toBeNull();
});

it('does not release a held ip that was renewed meanwhile', function () {
    $this->service->update(['paid_until' => now()->addMonth(), 'expired_at' => null, 'held_until' => null]);

    $result = app(ReleaseServiceIp::class)->handle($this->service, 'Hold ended', 'service-ip-released', ReleaseServiceIp::HOLD_ENDED);

    expect($result)->toBeNull()
        ->and($this->service->fresh()->ended_at)->toBeNull()
        ->and($this->account->fresh()->ip_id)->toBe($this->ip->id);
});

it('does not let staff release an ip that was renewed meanwhile', function () {
    $result = app(ReleaseServiceIp::class)->handle($this->service, 'Released by staff', 'service-ip-released', ReleaseServiceIp::LAPSED);

    expect($result)->toBeNull()
        ->and($this->service->fresh()->ended_at)->toBeNull();
});
