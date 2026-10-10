<?php

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AddressList;
use App\Models\Ip;
use App\Models\Ipoe;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Pool;
use Illuminate\Support\Facades\DB;

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
