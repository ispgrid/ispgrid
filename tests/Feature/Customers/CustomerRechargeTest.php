<?php

use App\Enums\AccountStatus;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Models\Radacct;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 14:30:00'));
    AppSetting::put('date_format', 'd/m/Y');
    AppSetting::put('expiry_mode', 'exact');
    $this->actingAs(User::factory()->create());
    $this->plan = Plan::factory()->create(['name' => 'Home 25', 'validity' => 30, 'price' => 599]);
});

it('opens with the customer\'s plan, the expiry it gives and its price', function () {
    $account = Account::factory()->create(['plan_id' => $this->plan->id, 'expiry' => '2026-11-01 10:00:00']);

    Livewire::test('pages::customers.show', ['account' => $account->id])
        ->call('openRecharge')
        ->assertSet('recharge_plan_id', (string) $this->plan->id)
        ->assertSet('recharge_expiry', '2026-12-01T10:00')
        ->assertSet('recharge_amount', '599.00')
        ->assertDispatched('modal-open', name: 'recharge');
});

it('fills the expiry and price of another plan when staff choose it', function () {
    AppSetting::put('expiry_mode', 'midnight');
    $other = Plan::factory()->create(['validity' => 7, 'price' => 149.5]);
    $account = Account::factory()->create(['plan_id' => null, 'expiry' => null]);

    Livewire::test('pages::customers.show', ['account' => $account->id])
        ->call('openRecharge')
        ->assertSet('recharge_plan_id', '')
        ->set('recharge_plan_id', (string) $other->id)
        ->assertSet('recharge_expiry', '2026-10-18T00:00')
        ->assertSet('recharge_amount', '149.50');
});

it('recharges and activates an inactive customer', function () {
    $account = Account::factory()->create(['status_id' => AccountStatus::Inactive, 'plan_id' => null, 'expiry' => null]);

    Livewire::test('pages::customers.show', ['account' => $account->id])
        ->call('openRecharge')
        ->set('recharge_plan_id', (string) $this->plan->id)
        ->set('recharge_amount', '500')
        ->set('recharge_note', ' Discount ')
        ->call('recharge')
        ->assertHasNoErrors()
        ->assertSet('status', '1')
        ->assertDispatched('modal-close', name: 'recharge')
        ->assertDispatched('toast', message: 'Recharged until 09/11/2026 14:30. The customer is Active now.', variant: 'success');

    expect(Recharge::query()->sole())->amount->toBe('500.00')->note->toBe('Discount')
        ->and($account->fresh()->status_id)->toBe(AccountStatus::Active);
});

it('disconnects an online customer after the recharge', function () {
    Queue::fake();
    $account = Account::factory()->create(['username' => 'alice', 'status_id' => AccountStatus::Active, 'plan_id' => $this->plan->id, 'expiry' => '2026-10-05 10:00:00']);
    Radacct::factory()->create(['username' => 'alice']);

    Livewire::test('pages::customers.show', ['account' => $account->id])
        ->call('openRecharge')
        ->call('recharge')
        ->assertDispatched('toast', message: 'Recharged until 09/11/2026 14:30. They are disconnected so the change applies now.', variant: 'success');

    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->username === 'alice');
});

it('checks the recharge before saving it', function (string $field, string $value) {
    $account = Account::factory()->create(['plan_id' => $this->plan->id]);

    Livewire::test('pages::customers.show', ['account' => $account->id])
        ->call('openRecharge')
        ->set($field, $value)
        ->call('recharge')
        ->assertHasErrors([$field]);

    expect(Recharge::query()->count())->toBe(0);
})->with([
    'no plan' => ['recharge_plan_id', ''],
    'an expiry in the past' => ['recharge_expiry', '2026-10-01T10:00'],
    'not a date' => ['recharge_expiry', 'tomorrow'],
    'a negative amount' => ['recharge_amount', '-1'],
    'three decimals' => ['recharge_amount', '1.005'],
]);

it('offers only plans that are not deleted', function () {
    Plan::factory()->trashed()->create(['name' => 'Old plan']);

    Livewire::test('pages::customers.show', ['account' => Account::factory()->create()->id])
        ->assertSee('Home 25 (30 days, 599.00)')
        ->assertDontSee('Old plan');
});

it('warns that a recharge keeps a Suspended status', function () {
    Livewire::test('pages::customers.show', ['account' => Account::factory()->suspended()->create()->id])
        ->assertSee('A recharge keeps the status Suspended.');
});
