<?php

use App\Enums\AccountStatus;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Radacct;
use App\Models\Radpostauth;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 12:00:00'));
    AppSetting::put('date_format', 'd/m/Y');
    $this->actingAs(User::factory()->create(['name' => 'Asha Rao']));
    Nas::factory()->create(['nasname' => '10.0.0.1', 'shortname' => 'core']);
});

function customerPage(Account $account): Testable
{
    return Livewire::test('pages::customers.show', ['account' => $account->id]);
}

it('shows the open sessions and disconnects one of them', function () {
    Queue::fake();
    $account = Account::factory()->create(['username' => 'alice']);
    $session = Radacct::factory()->create(['username' => 'alice', 'nasipaddress' => '10.0.0.1', 'framedipaddress' => '10.10.10.5']);

    customerPage($account)
        ->assertSee('10.10.10.5')
        ->assertSee('core')
        ->call('disconnect', $session->radacctid)
        ->assertDispatched('toast', message: 'Disconnect sent to the router. The session closes when the router confirms.', variant: 'success');

    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->username === 'alice' && $job->sessionId === $session->radacctid);
    expect(Activity::query()->where(['event' => 'disconnected', 'subject_id' => $account->id])->exists())->toBeTrue();
});

it('disconnects every session at once', function () {
    Queue::fake();
    $account = Account::factory()->create(['username' => 'alice']);
    Radacct::factory()->count(2)->create(['username' => 'alice']);

    customerPage($account)->call('disconnect');

    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->sessionId === null);
});

it('says when the customer is not online', function () {
    Queue::fake();

    customerPage(Account::factory()->create())
        ->assertSee('Not online.')
        ->call('disconnect')
        ->assertDispatched('toast', message: 'The customer is not online.', variant: 'success');

    Queue::assertNothingPushed();
});

it('shows the usage since the last recharge, today and this month, against the plan limits', function () {
    $plan = Plan::factory()->create(['limit_after' => 100, 'daily_data_gb' => null]);
    $account = Account::factory()->create(['username' => 'alice', 'plan_id' => $plan->id, 'usage_since' => '2026-10-05 00:00:00']);
    Radacct::factory()->closed()->create(['username' => 'alice', 'acctstarttime' => '2026-10-01 08:00:00', 'acctinputoctets' => 5_368_709_120, 'acctsessiontime' => 3600]);
    Radacct::factory()->closed()->create(['username' => 'alice', 'acctstarttime' => '2026-10-06 08:00:00', 'acctoutputoctets' => 1_073_741_824, 'acctsessiontime' => 3900]);

    customerPage($account)
        ->assertSeeInOrder(['Since the last recharge', '1.00 GB / 100.0 GB', '1 h 05 min'])
        ->assertSeeInOrder(['This month', '6.00 GB', '2 h 05 min']);
});

it('lists the latest login attempts with a link to the full log', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    Radpostauth::factory()->rejected('Account is suspended')->create(['username' => 'alice']);

    customerPage($account)
        ->assertSee('Account is suspended')
        ->assertSee(route('login-log.index', ['username' => 'alice']), false);
});

it('lists the recharges with their amounts', function () {
    AppSetting::put('currency_symbol', '₹');
    AppSetting::put('number_grouping', 'indian');
    $account = Account::factory()->create();
    Recharge::factory()->create([
        'account_id' => $account->id, 'plan_id' => Plan::factory()->create(['name' => 'Home 25'])->id,
        'new_expiry' => '2026-11-09 14:30:00', 'amount' => 123456, 'note' => 'Paid cash', 'user_id' => auth()->id(),
    ]);

    customerPage($account)->assertSeeInOrder(['Home 25', '09/11/2026 14:30', '₹1,23,456.00', 'Paid cash', 'Asha Rao']);
});

it('shows the customer\'s own activity', function () {
    $account = Account::factory()->create(['status_id' => AccountStatus::Active]);
    Account::factory()->create(['fullname' => 'Someone Else']);

    customerPage($account)
        ->set('status', '3')
        ->set('remark', 'Unpaid since September')
        ->call('changeStatus')
        ->assertSee('Status changed')
        ->assertSee('Unpaid since September')
        ->assertDontSee('Someone Else');
});

it('warns when a disconnect failed and the session is still open', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    Radacct::factory()->create(['username' => 'alice', 'acctstarttime' => '2026-10-10 09:00:00']);
    (new DisconnectAccount('alice'))->failed(new RuntimeException('10.0.0.1: no answer'));

    customerPage($account)->assertSee('The last disconnect failed')->assertSee('10.0.0.1: no answer');
});

it('drops the warning once the session it was about has ended', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    (new DisconnectAccount('alice'))->failed(new RuntimeException('10.0.0.1: no answer'));
    $this->travel(5)->minutes();
    Radacct::factory()->create(['username' => 'alice', 'acctstarttime' => now()]);

    customerPage($account)->assertDontSee('The last disconnect failed');
});
