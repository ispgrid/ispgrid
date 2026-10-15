<?php

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AddressList;
use App\Models\AppSetting;
use App\Models\Ip;
use App\Models\Ipoe;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @return list<array{0: string, 1: string, 2: string}>
 */
function userRows(string $table, string $username): array
{
    return DB::table($table)->where('username', $username)->orderBy('id')->get()
        ->map(fn (object $row): array => [$row->attribute, $row->op, $row->value])
        ->all();
}

function syncAccount(Account $account, ?int $previousIpId = null): void
{
    app(SyncAccountToRadius::class)->handle($account->fresh(), $previousIpId);
}

it('writes the check rows and the plan of a PPPoE customer', function () {
    $account = Account::factory()->create([
        'username' => 'alice', 'password' => 'secret12', 'mac_type' => 2, 'expiry' => '2030-01-01 00:00:00',
        'plan_id' => Plan::factory()->create(['slug' => 'home_25'])->id,
    ]);

    syncAccount($account);

    expect(userRows('radcheck', 'alice'))->toBe([
        ['Cleartext-Password', ':=', 'secret12'],
        ['Acct-Service-Type', ':=', 'PPP'],
        ['Acct-Status-Id', ':=', '1'],
        ['Acct-Mac-Type', ':=', '2'],
        ['Expiration', ':=', '01 Jan 2030 00:00'],
    ])
        ->and(DB::table('radusergroup')->where('username', 'alice')->get(['groupname', 'priority'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['groupname' => 'home_25', 'priority' => 1]])
        ->and(userRows('radreply', 'alice'))->toBe([]);
});

it('leaves out the expiry of a customer who never expires and adds the usage start and the bound router', function () {
    $account = Account::factory()->create([
        'username' => 'bob', 'expiry' => null, 'usage_since' => '2026-10-07 18:00:00',
        'nas_id' => Nas::factory()->create(['nasname' => '10.0.0.1'])->id,
    ]);

    syncAccount($account);

    $attributes = array_column(userRows('radcheck', 'bob'), 2, 0);

    expect($attributes)->not->toHaveKey('Expiration')
        ->and($attributes['Isp-Usage-Since'])->toBe('07 Oct 2026 18:00')
        ->and($attributes['Isp-Nas'])->toBe('10.0.0.1');
});

it('writes each status as its number', function (AccountStatus $status) {
    $account = Account::factory()->create(['username' => 'carol', 'status_id' => $status]);

    syncAccount($account);

    expect(array_column(userRows('radcheck', 'carol'), 2, 0)['Acct-Status-Id'])->toBe((string) $status->value);
})->with(AccountStatus::cases());

it('writes the static IP, custom speed, pool, address lists and custom attributes', function () {
    $account = Account::factory()->create([
        'username' => 'dave',
        'ip_id' => Ip::factory()->create(['address' => '10.10.10.123'])->id,
        'pool_id' => Pool::factory()->create(['name' => 'business'])->id,
        'override_plan' => true, 'override_upload' => '10M', 'override_download' => '50M',
        'override_priority' => 2, 'override_min_upload' => '1M', 'override_min_download' => '5M',
    ]);
    $account->addressLists()->attach([
        AddressList::factory()->create(['name' => 'zeta'])->id,
        AddressList::factory()->create(['name' => 'alpha'])->id,
    ]);
    $account->radiusAttributes()->createMany([
        ['attribute' => 'Framed-Route', 'op' => '+=', 'value' => '203.0.113.8/29'],
        ['attribute' => 'Idle-Timeout', 'op' => ':=', 'value' => '600'],
    ]);

    syncAccount($account);

    expect(userRows('radreply', 'dave'))->toBe([
        ['Framed-IP-Address', ':=', '10.10.10.123'],
        ['Mikrotik-Rate-Limit', ':=', '10M/50M 0/0 0/0 0/0 2 1M/5M'],
        ['Framed-Pool', ':=', 'business'],
        ['Mikrotik-Address-List', '+=', 'alpha'],
        ['Mikrotik-Address-List', '+=', 'zeta'],
        ['Framed-Route', '+=', '203.0.113.8/29'],
        ['Idle-Timeout', ':=', '600'],
    ])->and(Ip::query()->where('address', '10.10.10.123')->value('is_used'))->toBeTrue();
});

it('maps an IPoE customer by IP and sends neither the IP nor a pool', function () {
    $account = Account::factory()->ipoe()->create([
        'username' => 'erin', 'password' => 'generated123', 'mac_type' => 3,
        'ip_id' => Ip::factory()->create(['address' => '172.16.30.20'])->id,
        'pool_id' => Pool::factory()->create()->id,
    ]);

    syncAccount($account);

    expect(Ipoe::query()->sole()->only(['account_id', 'username', 'ip_address']))
        ->toBe(['account_id' => $account->id, 'username' => 'erin', 'ip_address' => '172.16.30.20'])
        ->and(array_column(userRows('radcheck', 'erin'), 2, 0))->toMatchArray(['Acct-Service-Type' => 'IPOE', 'Acct-Mac-Type' => '1'])
        ->and(userRows('radreply', 'erin'))->toBe([]);
});

it('removes the ipoes row when the account is no longer IPoE', function () {
    $account = Account::factory()->ipoe()->create(['password' => 'x1234567', 'ip_id' => Ip::factory()->create()->id]);
    syncAccount($account);

    $account->update(['service_type' => ServiceType::Ppp]);
    syncAccount($account);

    expect(Ipoe::query()->count())->toBe(0)
        ->and(array_column(userRows('radreply', $account->username), 0))->toBe(['Framed-IP-Address']);
});

it('frees the previous IP and marks the new one', function () {
    $old = Ip::factory()->create(['is_used' => true]);
    $new = Ip::factory()->create();
    $account = Account::factory()->create(['ip_id' => $new->id]);

    syncAccount($account, $old->id);

    expect($old->fresh()->is_used)->toBeFalse()
        ->and($new->fresh()->is_used)->toBeTrue();
});

it('removes every row of a deleted customer and frees the IP the delete took away', function () {
    $ip = Ip::factory()->create(['address' => '172.16.30.21']);
    $account = Account::factory()->ipoe()->create(['username' => 'frank', 'password' => 'x1234567', 'ip_id' => $ip->id]);
    syncAccount($account);

    $account->update(['ip_id' => null]);
    $account->delete();
    syncAccount($account, $ip->id);

    expect(userRows('radcheck', 'frank'))->toBe([])
        ->and(userRows('radreply', 'frank'))->toBe([])
        ->and(DB::table('radusergroup')->where('username', 'frank')->count())->toBe(0)
        ->and(Ipoe::query()->count())->toBe(0)
        ->and($ip->fresh()->is_used)->toBeFalse();
});

it('writes nothing for a leased line', function () {
    $account = Account::factory()->leasedLine()->create(['username' => 'grace']);

    syncAccount($account);

    expect(userRows('radcheck', 'grace'))->toBe([])
        ->and(DB::table('radusergroup')->where('username', 'grace')->count())->toBe(0);
});

it('writes no plan row for a customer without a plan', function () {
    $account = Account::factory()->create(['username' => 'heidi', 'plan_id' => null]);

    syncAccount($account);

    expect(DB::table('radusergroup')->where('username', 'heidi')->count())->toBe(0)
        ->and(userRows('radcheck', 'heidi'))->not->toBe([]);
});

it('replaces the rows of its own customer only', function () {
    $mine = Account::factory()->create(['username' => 'ivan']);
    $other = Account::factory()->create(['username' => 'judy']);
    syncAccount($mine);
    syncAccount($other);

    $mine->update(['password' => 'changed12']);
    syncAccount($mine);

    expect(array_column(userRows('radcheck', 'ivan'), 2, 0)['Cleartext-Password'])->toBe('changed12')
        ->and(count(userRows('radcheck', 'ivan')))->toBe(5)
        ->and(count(userRows('radcheck', 'judy')))->toBe(5);
});

it('says whether FreeRADIUS reads different rows after the sync', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    $sync = new SyncAccountToRadius;

    expect($sync->handle($account))->toBeTrue()
        ->and($sync->handle($account))->toBeFalse();

    $account->update(['status_id' => AccountStatus::Suspended]);

    expect($sync->handle($account))->toBeTrue();
});

it('writes the first-login validity instead of an expiry for a waiting Hotspot customer', function () {
    $account = Account::factory()->hotspotPending(180)->create(['username' => 'hs1', 'password' => 'secret12', 'mac_type' => 1]);

    syncAccount($account);

    expect(userRows('radcheck', 'hs1'))->toBe([
        ['Cleartext-Password', ':=', 'secret12'],
        ['Acct-Service-Type', ':=', 'HOTSPOT'],
        ['Acct-Status-Id', ':=', '1'],
        ['Acct-Mac-Type', ':=', '1'],
        ['Hotspot-First-Login-Validity', ':=', '180'],
    ]);
});

it('never writes a first-login validity for a PPPoE or IPoE account', function (Closure $make) {
    $account = $make();

    expect(fn () => syncAccount($account))->toThrow(LogicException::class);
})->with([
    'PPPoE' => [fn () => Account::factory()->create(['expiry' => null, 'hotspot_pending_validity' => 180])],
    'IPoE' => [fn () => Account::factory()->ipoe()->create(['expiry' => null, 'hotspot_pending_validity' => 180])],
]);

it('refuses an account with both an expiry and a waiting validity', function () {
    $account = Account::factory()->hotspot()->create(['expiry' => '2030-01-01 00:00:00', 'hotspot_pending_validity' => 180]);

    expect(fn () => syncAccount($account))->toThrow(LogicException::class);
});

it('reads the clock again under a lock, so a copy loaded before the first login cannot restart it', function () {
    $account = Account::factory()->hotspotPending(180)->create(['username' => 'hs1']);
    $stale = $account->fresh();
    // FreeRADIUS stamps the first login (isp post-auth step 9b) after the copy was loaded.
    DB::table('accounts')->where('id', $account->id)->update([
        'expiry' => '2030-01-01 10:00:00', 'hotspot_pending_validity' => null, 'hotspot_first_login_at' => '2030-01-01 07:00:00',
    ]);

    app(SyncAccountToRadius::class)->handle($stale);

    expect(array_column(userRows('radcheck', 'hs1'), 2, 0))
        ->toHaveKey('Expiration', '01 Jan 2030 10:00')
        ->not->toHaveKey('Hotspot-First-Login-Validity')
        ->and($stale->hotspot_pending_validity)->toBeNull()
        ->and($stale->isDirty())->toBeFalse();
});

it('keeps the validity row when staff edit a waiting customer or change their status', function () {
    turnHotspotOn();
    AppSetting::put('phone_digits', '10');
    $this->actingAs(User::factory()->create());
    $account = Account::factory()->hotspotPending(180)->create(['username' => 'hs1', 'mobile' => null]);
    syncAccount($account);

    Livewire::test('pages::customers.form', ['account' => $account->id])->set('fullname', 'New Name')->call('save')->assertHasNoErrors();
    Livewire::test('pages::customers.show', ['account' => $account->id])
        ->set('status', (string) AccountStatus::Suspended->value)
        ->set('remark', 'Unpaid')
        ->call('changeStatus')
        ->assertHasNoErrors();

    expect(array_column(userRows('radcheck', 'hs1'), 2, 0))
        ->toHaveKey('Hotspot-First-Login-Validity', '180')
        ->not->toHaveKey('Expiration')
        ->and($account->fresh()->hotspot_pending_validity)->toBe(180);
});

it('gives a waiting customer the validity row back when they are restored', function () {
    $account = Account::factory()->hotspotPending(180)->create(['username' => 'hs1']);
    $account->delete();
    syncAccount($account);
    expect(userRows('radcheck', 'hs1'))->toBe([]);

    $account->restore();
    syncAccount($account);

    expect(array_column(userRows('radcheck', 'hs1'), 2, 0))->toHaveKey('Hotspot-First-Login-Validity', '180');
});
