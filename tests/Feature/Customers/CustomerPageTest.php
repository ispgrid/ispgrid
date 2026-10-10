<?php

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Ip;
use App\Models\Ipoe;
use App\Models\IpRange;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    AppSetting::put('date_format', 'd/m/Y');
    $this->actingAs(User::factory()->create());
});

function showCustomer(Account $account): Testable
{
    return Livewire::test('pages::customers.show', ['account' => $account->id]);
}

it('shows what the customer has and how to reach them', function () {
    AppSetting::put('phone_country_code', '+91');
    $account = Account::factory()->create([
        'username' => 'alice', 'fullname' => 'Alice Rao', 'mobile' => '9876543210', 'expiry' => '2030-01-31 18:00:00',
        'plan_id' => Plan::factory()->create(['name' => 'Home 25'])->id,
    ]);

    showCustomer($account)
        ->assertSee('alice')
        ->assertSee('Alice Rao')
        ->assertSee('+91 9876543210')
        ->assertSee('Home 25')
        ->assertSee('31/01/2030 18:00')
        ->assertSee('PPPoE')
        ->assertSee('Any router');
});

it('gives the technician the IP, mask, gateway and DNS', function () {
    $range = IpRange::factory()->create([
        'network' => '172.16.30.0/24', 'gateway' => '172.16.30.1', 'dns1' => '1.1.1.1', 'dns2' => '8.8.8.8',
    ]);
    $ip = Ip::factory()->create(['ip_range_id' => $range->id, 'address' => '172.16.30.20']);

    showCustomer(Account::factory()->ipoe()->create(['ip_id' => $ip->id]))
        ->assertSee('172.16.30.20')
        ->assertSee('255.255.255.0')
        ->assertSee('172.16.30.1')
        ->assertSee('1.1.1.1, 8.8.8.8');
});

it('lists the bound devices and resets them', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    DB::table('account_macs')->insert(['username' => 'alice', 'mac_norm' => 'BC2411003020', 'learned_at' => '2026-10-01 10:00:00']);

    showCustomer($account)
        ->assertSee('BC:24:11:00:30:20')
        ->call('resetDevices')
        ->assertDispatched('toast', variant: 'success')
        ->assertDontSee('BC:24:11:00:30:20');

    expect(DB::table('account_macs')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'devices-reset')->sole()->properties['macs'])->toBe(['BC:24:11:00:30:20']);
});

it('points to the customer that holds a device this one was refused for', function () {
    $owner = Account::factory()->create(['username' => 'bob']);
    $account = Account::factory()->create(['username' => 'alice']);
    DB::table('account_macs')->insert(['username' => 'bob', 'mac_norm' => 'BC2411003020']);
    DB::table('radpostauth')->insert([
        'username' => 'alice', 'reply' => 'Access-Reject', 'reply_msg' => 'MAC BC:24:11:00:30:20 is bound to another account',
        'mac' => 'BC:24:11:00:30:20', 'authdate' => now(),
    ]);

    showCustomer($account)
        ->assertSee('is bound to bob')
        ->assertSee(route('customers.show', $owner->id));
});

it('says nothing about another customer once a later login worked', function () {
    Account::factory()->create(['username' => 'bob']);
    $account = Account::factory()->create(['username' => 'alice']);
    DB::table('account_macs')->insert(['username' => 'bob', 'mac_norm' => 'BC2411003020']);
    DB::table('radpostauth')->insert([
        ['username' => 'alice', 'reply' => 'Access-Reject', 'reply_msg' => 'MAC BC:24:11:00:30:20 is bound to another account', 'mac' => 'BC:24:11:00:30:20', 'authdate' => now()->subMinute()],
        ['username' => 'alice', 'reply' => 'Access-Accept', 'reply_msg' => '', 'mac' => 'AA:00:00:00:00:01', 'authdate' => now()],
    ]);

    showCustomer($account)->assertDontSee('is bound to bob');
});

it('unlocks a customer locked after wrong passwords', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    DB::table('reject_counters')->insert(['username' => 'alice', 'mac' => '', 'counter' => 31, 'last_update' => now()]);

    showCustomer($account)
        ->assertSee('31 failed logins')
        ->call('unlock')
        ->assertDispatched('toast', variant: 'success');

    expect(DB::table('reject_counters')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'unlocked')->count())->toBe(1);
});

it('changes the status with a remark and rewrites it for FreeRADIUS', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    app(SyncAccountToRadius::class)->handle($account);

    showCustomer($account)
        ->set('status', '3')
        ->set('remark', 'Unpaid for two months')
        ->call('changeStatus')
        ->assertHasNoErrors()
        ->assertDispatched('toast', variant: 'success');

    $activity = Activity::query()->where('event', 'status-changed')->sole();

    expect($account->fresh()->status_id)->toBe(AccountStatus::Suspended)
        ->and(DB::table('radcheck')->where(['username' => 'alice', 'attribute' => 'Acct-Status-Id'])->value('value'))->toBe('3')
        ->and(collect($activity->attribute_changes)->toArray())->toEqual([
            'old' => ['status' => 'Active'],
            'attributes' => ['status' => 'Suspended', 'remark' => 'Unpaid for two months'],
        ])
        ->and(Activity::query()->where('subject_id', $account->id)->where('event', 'updated')->count())->toBe(0);
});

it('asks why the status changes', function () {
    showCustomer(Account::factory()->create())->set('status', '2')->set('remark', '  ')->call('changeStatus')->assertHasErrors(['remark']);
});

it('refuses a status that does not exist or is already set', function (string $status) {
    showCustomer(Account::factory()->create())->set('status', $status)->set('remark', 'Test')->call('changeStatus')->assertHasErrors(['status']);
})->with(['9', '1']);

it('deletes a customer: frees the IP, drops the pool and devices, and records them', function () {
    $ip = Ip::factory()->create(['address' => '10.0.0.5']);
    $account = Account::factory()->create([
        'username' => 'alice', 'ip_id' => $ip->id, 'pool_id' => Pool::factory()->create(['name' => 'pppoe'])->id,
    ]);
    app(SyncAccountToRadius::class)->handle($account);
    DB::table('account_macs')->insert([
        'username' => 'alice', 'mac_norm' => 'BC2411003020', 'learned_at' => '2026-10-01 10:00:00', 'last_seen' => '2026-10-09 18:30:00',
    ]);
    DB::table('reject_counters')->insert(['username' => 'alice', 'mac' => '', 'counter' => 2, 'last_update' => now()]);

    showCustomer($account)->call('delete')->assertDispatched('toast', variant: 'success')->assertSee('Restore');

    $deleted = Account::withTrashed()->findOrFail($account->id);
    $activity = Activity::query()->where('subject_id', $account->id)->where('event', 'deleted')->sole();

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->ip_id)->toBeNull()
        ->and($deleted->pool_id)->toBeNull()
        ->and($ip->fresh()->is_used)->toBeFalse()
        ->and(DB::table('radcheck')->where('username', 'alice')->count())->toBe(0)
        ->and(DB::table('radreply')->where('username', 'alice')->count())->toBe(0)
        ->and(DB::table('account_macs')->count())->toBe(0)
        ->and(DB::table('reject_counters')->count())->toBe(0)
        ->and(collect($activity->attribute_changes)->toArray()['old'])->toEqual([
            'ip_address' => '10.0.0.5', 'pool' => 'pppoe', 'devices' => 'BC:24:11:00:30:20',
        ])
        ->and($activity->properties['devices'])->toBe([
            ['mac' => 'BC:24:11:00:30:20', 'bound' => '2026-10-01 10:00:00', 'last_seen' => '2026-10-09 18:30:00'],
        ]);
});

it('removes the ipoes row of a deleted IPoE customer', function () {
    $account = Account::factory()->ipoe()->create(['password' => 'x1234567', 'ip_id' => Ip::factory()->create()->id]);
    app(SyncAccountToRadius::class)->handle($account);

    showCustomer($account)->call('delete');

    expect(Ipoe::query()->count())->toBe(0);
});

it('restores a deleted customer as Inactive with the default pool of their type', function () {
    $pool = Pool::factory()->create(['name' => 'pppoe']);
    AppSetting::put('default_pool_ppp', (string) $pool->id);
    $account = Account::factory()->trashed()->create(['username' => 'alice']);

    showCustomer($account)->call('restore')->assertDispatched('toast', variant: 'success');

    expect($account->fresh())->trashed()->toBeFalse()->status_id->toBe(AccountStatus::Inactive)->pool_id->toBe($pool->id)
        ->and(DB::table('radcheck')->where(['username' => 'alice', 'attribute' => 'Acct-Status-Id'])->value('value'))->toBe('2')
        ->and(DB::table('radreply')->where(['username' => 'alice', 'attribute' => 'Framed-Pool'])->value('value'))->toBe('pppoe');
});

it('restores a deleted IPoE customer on the next free IP of the default range', function () {
    $range = IpRange::factory()->create();
    Ip::factory()->create(['ip_range_id' => $range->id, 'address' => '172.16.30.20']);
    AppSetting::put('default_ip_range_ipoe', (string) $range->id);
    $account = Account::factory()->ipoe()->trashed()->create(['username' => 'erin', 'password' => 'x1234567']);

    showCustomer($account)->call('restore')->assertDispatched('toast', variant: 'success');

    expect(Ipoe::query()->sole()->ip_address)->toBe('172.16.30.20')
        ->and(Ip::query()->where('address', '172.16.30.20')->value('is_used'))->toBeTrue();
});

it('restores an IPoE customer without an IP when the default range has none free, and says so', function () {
    $account = Account::factory()->ipoe()->trashed()->create(['password' => 'x1234567']);

    showCustomer($account)->call('restore')->assertDispatched(
        'toast',
        message: 'Customer restored as Inactive. Give them an IP on the Network tab: the default IPoE range has no free address.',
        variant: 'success',
    );

    expect($account->fresh()->trashed())->toBeFalse()
        ->and(Ipoe::query()->count())->toBe(0);
});

it('refuses to restore a customer whose plan was deleted', function () {
    $plan = Plan::factory()->create(['name' => 'Old plan']);
    $account = Account::factory()->trashed()->create(['plan_id' => $plan->id]);
    $plan->delete();

    showCustomer($account)->call('restore')->assertDispatched(
        'toast',
        message: 'First restore what this customer uses: the plan Old plan.',
        variant: 'danger',
    );

    expect($account->fresh()->trashed())->toBeTrue()
        ->and(DB::table('radcheck')->where('username', $account->username)->count())->toBe(0);
});

it('shows nothing for an unknown customer', function () {
    $this->get(route('customers.show', 999999))->assertNotFound();
});
