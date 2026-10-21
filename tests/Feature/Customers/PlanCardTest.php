<?php

use App\Enums\AccountStatus;
use App\Enums\AdvanceRenewalStatus;
use App\Enums\Permission;
use App\Enums\RechargeKind;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Models\Radacct;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 14:30:00'));
    AppSetting::put('date_format', 'd/m/Y');
    AppSetting::put('expiry_mode', 'exact');
    $this->actingAs(User::factory()->create());
    $this->plan = Plan::factory()->create(['name' => 'Home 25', 'validity' => 30, 'price' => 599, 'upload' => '10M', 'download' => '25M']);
    $this->account = Account::factory()->create(['username' => 'alice', 'plan_id' => $this->plan->id, 'expiry' => '2026-10-31 00:00:00']);
});

function planCard(Account $account): Testable
{
    return Livewire::test('pages::customers.plan-card', ['accountId' => $account->id]);
}

it('shows the plan, its dates, auto renew, a waiting renewal, an override speed and whether the customer is online', function () {
    $next = Plan::factory()->create(['name' => 'Home 100']);
    $this->account->update(['auto_renew' => true, 'override_plan' => true, 'override_upload' => '25M', 'override_download' => '70M', 'override_until' => '2026-10-20 00:00:00']);
    Recharge::factory()->for($this->account)->create(['plan_id' => $this->plan->id, 'created_at' => '2026-10-01 09:00:00', 'old_expiry' => null]);
    AdvanceRenewal::factory()->for($this->account)->create(['plan_id' => $next->id, 'amount' => 999, 'starts_at' => '2026-10-31 00:00:00', 'expiry' => '2026-11-30 00:00:00']);
    Radacct::factory()->create(['username' => 'alice']);

    planCard($this->account)
        ->assertSeeInOrder(['Home 25', 'Online', '01/10/2026 09:00', '31/10/2026 00:00', 'in 21 days', 'On', 'The advance renewal starts instead',
            'Waiting', 'Home 100', '31/10/2026 00:00', '30/11/2026 00:00', 'Cancel', 'Override speed 25M/70M until 20/10/2026 00:00']);
});

it('shows the buttons each permission allows, and refuses the action anyway', function (Permission $missing, array $hidden, string $action) {
    $this->actingAs(staffLacking($missing));

    $card = planCard($this->account);
    foreach ($hidden as $method) {
        $card->assertDontSeeHtml("wire:click=\"{$method}\"");
    }

    $card->call($action)->assertForbidden();
})->with([
    'recharge' => [Permission::RechargeCustomers, ['openChangePlan', 'openAdvance', 'openChangeExpiry'], 'changePlan'],
    'edit' => [Permission::EditCustomers, ['openOverrideSpeed'], 'saveOverrideSpeed'],
    'status' => [Permission::ChangeCustomerStatus, ['openTerminate'], 'terminate'],
]);

it('shows every button to a role that has the boxes', function () {
    planCard($this->account)
        ->assertSeeHtml('open-recharge')
        ->assertSeeHtml('wire:click="openChangePlan"')
        ->assertSeeHtml('wire:click="openAdvance"')
        ->assertSeeHtml('wire:click="openChangeExpiry"')
        ->assertSeeHtml('wire:click="openOverrideSpeed"')
        ->assertSeeHtml('wire:click="openTerminate"');
});

it('changes the plan from now with the edited amount and disconnects an online customer', function () {
    Queue::fake();
    Radacct::factory()->create(['username' => 'alice']);
    $other = Plan::factory()->create(['name' => 'Home 100', 'validity' => 30, 'price' => 999]);

    planCard($this->account)
        ->call('openChangePlan')
        ->set('change_plan_id', (string) $other->id)
        ->assertSet('change_expiry', '2026-11-09T14:30')
        ->assertSet('change_amount', '999.00')
        ->set('change_amount', '900')
        ->call('changePlan')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'change-plan')
        ->assertDispatched('toast', message: 'Plan changed until 09/11/2026 14:30. They are disconnected so the change applies now.', variant: 'success');

    $recharge = Recharge::query()->sole();
    expect($this->account->fresh()->plan_id)->toBe($other->id)
        ->and($this->account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-11-09 14:30')
        ->and($recharge->kind)->toBe(RechargeKind::ChangePlan)
        ->and($recharge->amount)->toBe('900.00');
    Queue::assertPushed(DisconnectAccount::class);
});

it('leaves the current plan out of Change plan and says what time is lost', function () {
    $other = Plan::factory()->create(['name' => 'Home 100']);

    $card = planCard($this->account)
        ->call('openChangePlan')
        ->assertSet('change_plan_id', '')
        ->assertSee('The time left on the current plan (20 days 9 hours) ends now.');

    expect(array_keys($card->instance()->changePlans))->toBe([$other->id]);

    $card->set('change_plan_id', (string) $this->plan->id)->call('changePlan')->assertHasErrors('change_plan_id');
    expect(Recharge::query()->count())->toBe(0);
});

it('books a renewal in advance, and refuses a second one', function () {
    $next = Plan::factory()->create(['name' => 'Home 100', 'validity' => 30, 'price' => 999]);

    planCard($this->account)
        ->call('openAdvance')
        ->set('advance_plan_id', (string) $next->id)
        ->assertSet('advance_expiry', '2026-11-30T00:00')
        ->call('bookRenewal')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'renew-advance');

    expect(AdvanceRenewal::query()->sole())
        ->plan_id->toBe($next->id)
        ->amount->toBe('999.00');

    planCard($this->account)
        ->call('openAdvance')
        ->set('advance_plan_id', (string) $next->id)
        ->call('bookRenewal')
        ->assertHasErrors('advance_plan_id');
    expect(AdvanceRenewal::query()->count())->toBe(1);
});

it('cancels a waiting renewal', function () {
    $renewal = AdvanceRenewal::factory()->for($this->account)->create();

    planCard($this->account)->call('cancelRenewal')->assertDispatched('toast', message: 'Advance renewal cancelled.', variant: 'success');

    expect($renewal->fresh()->status)->toBe(AdvanceRenewalStatus::Cancelled);
});

it('changes the expiry, and disconnects only for an expiry less than a minute away', function () {
    Queue::fake();
    Radacct::factory()->create(['username' => 'alice']);

    planCard($this->account)
        ->call('openChangeExpiry')
        ->assertSet('new_expiry', '2026-10-31T00:00')
        ->set('new_expiry', '2026-11-05T00:00')
        ->set('expiry_note', 'Down 5 days')
        ->call('changeExpiry')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Expiry changed to 05/11/2026 00:00.', variant: 'success');
    Queue::assertNotPushed(DisconnectAccount::class);

    planCard($this->account)
        ->call('openChangeExpiry')
        ->set('new_expiry', '2026-10-10T14:30')
        ->call('changeExpiry')
        ->assertHasNoErrors();
    Queue::assertPushed(DisconnectAccount::class);
    expect($this->account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-10-10 14:30');
});

it('sets and removes an override speed', function () {
    Queue::fake();

    planCard($this->account)
        ->call('openOverrideSpeed')
        ->set('speed_upload', '25M')
        ->set('speed_download', '70M')
        ->set('speed_until', '2026-11-01T00:00')
        ->call('saveOverrideSpeed')
        ->assertHasNoErrors();

    expect($this->account->fresh())
        ->override_upload->toBe('25M')
        ->override_until->format('Y-m-d H:i')->toBe('2026-11-01 00:00');

    planCard($this->account)->call('removeOverrideSpeed')->assertHasNoErrors();

    expect($this->account->fresh()->override_plan)->toBeFalse();
});

it('ends a new override speed at the plan expiry unless one is set or the plan has ended', function () {
    planCard($this->account)->call('openOverrideSpeed')->assertSet('speed_until', '2026-10-31T00:00');

    $this->account->update(['override_plan' => true, 'override_upload' => '25M', 'override_download' => '70M', 'override_until' => '2026-10-20 00:00:00']);
    planCard($this->account)->call('openOverrideSpeed')->assertSet('speed_until', '2026-10-20T00:00');

    $ended = Account::factory()->create(['expiry' => '2026-10-01 00:00:00']);
    planCard($ended)->call('openOverrideSpeed')->assertSet('speed_until', '');
});

it('refuses an override speed MikroTik would not read, or one ending in the past', function () {
    planCard($this->account)
        ->call('openOverrideSpeed')
        ->set('speed_upload', '25 Mbps')
        ->set('speed_download', '70M')
        ->set('speed_until', '2026-10-01T00:00')
        ->call('saveOverrideSpeed')
        ->assertHasErrors(['speed_upload', 'speed_until']);
});

it('terminates with a comment', function () {
    Queue::fake();

    planCard($this->account)->call('openTerminate')->call('terminate')->assertHasErrors('terminate_note');

    planCard($this->account)
        ->set('terminate_note', 'Moved away')
        ->call('terminate')
        ->assertHasNoErrors()
        ->assertDispatched('account-changed');

    expect($this->account->fresh())
        ->status_id->toBe(AccountStatus::Terminated)
        ->plan_id->toBeNull();
});

it('shows a deleted customer without buttons', function () {
    $this->account->delete();

    planCard($this->account)
        ->assertSee('Home 25')
        ->assertDontSeeHtml('wire:click="openChangePlan"')
        ->assertDontSeeHtml('open-recharge');
});

it('opens the page Recharge dialog from the card', function () {
    Livewire::test('pages::customers.show', ['account' => $this->account->id])
        ->dispatch('open-recharge')
        ->assertDispatched('modal-open', name: 'recharge');
});

it('puts the Plan card first on the Details tab', function () {
    Livewire::test('pages::customers.show', ['account' => $this->account->id])
        ->assertSeeLivewire('pages::customers.plan-card');
});

it('says what auto renew will renew and when', function () {
    $this->account->update(['auto_renew' => true]);

    planCard($this->account)->assertSee('Renews Home 25 on 31/10/2026 00:00')->assertSee('Not recharged yet');
});
