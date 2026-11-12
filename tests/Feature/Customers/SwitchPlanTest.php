<?php

use App\Actions\Customers\SwitchPlan;
use App\Enums\AccountStatus;
use App\Enums\Permission;
use App\Enums\RechargeKind;
use App\Enums\ServiceType;
use App\Exceptions\CustomerNotExpired;
use App\Exceptions\FirstLoginPlanNeedsHotspot;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 14:30:00'));
    AppSetting::put('date_format', 'd/m/Y');
    $this->actingAs(User::factory()->create());
    $this->old = Plan::factory()->create(['slug' => 'home', 'name' => 'Home 25', 'price' => 599]);
    $this->new = Plan::factory()->create(['slug' => 'fiber', 'name' => 'Fiber 100', 'price' => 999]);
    $this->account = Account::factory()->create([
        'username' => 'alice', 'plan_id' => $this->old->id, 'expiry' => '2026-10-01 00:00:00', 'usage_since' => '2026-09-01 00:00:00',
    ]);
});

it('switches an expired customer to another plan without charging or a new expiry', function () {
    turnInvoicingOn();

    $row = app(SwitchPlan::class)->handle($this->account->id, $this->new->id, 'Wants fiber');

    expect($this->account->fresh())
        ->plan_id->toBe($this->new->id)
        ->expiry->format('Y-m-d H:i')->toBe('2026-10-01 00:00')
        ->usage_since->format('Y-m-d H:i')->toBe('2026-09-01 00:00')
        ->status_id->toBe(AccountStatus::Active)
        ->and($row->fresh())
        ->kind->toBe(RechargeKind::SwitchPlan)
        ->amount->toBe('0.00')
        ->plan_id->toBe($this->new->id)
        ->old_plan_id->toBe($this->old->id)
        ->old_status->toBe(AccountStatus::Active)
        ->invoice_id->toBeNull()
        ->and($row->old_expiry->format('Y-m-d H:i'))->toBe('2026-10-01 00:00')
        ->and($row->new_expiry->format('Y-m-d H:i'))->toBe('2026-10-01 00:00')
        ->and(Invoice::query()->count())->toBe(0)
        ->and(NotificationLog::query()->count())->toBe(0)
        ->and(DB::table('radusergroup')->where('username', 'alice')->value('groupname'))->toBe('fiber');

    $entry = Activity::query()->where('subject_id', $this->account->id)->where('event', 'plan-switched')->sole();
    expect($entry->attribute_changes['old']['plan'])->toBe('Home 25')
        ->and($entry->attribute_changes['attributes']['plan'])->toBe('Fiber 100');
});

it('switches an Inactive customer with no plan or expiry', function () {
    $this->account->update(['status_id' => AccountStatus::Inactive, 'plan_id' => null, 'expiry' => null]);

    app(SwitchPlan::class)->handle($this->account->id, $this->new->id, null);

    expect($this->account->fresh())->plan_id->toBe($this->new->id)->status_id->toBe(AccountStatus::Inactive);
});

it('refuses a customer with time left', function () {
    $this->account->update(['expiry' => '2026-10-20 00:00:00']);

    app(SwitchPlan::class)->handle($this->account->id, $this->new->id, null);
})->throws(CustomerNotExpired::class);

it('refuses a first-login plan for a PPPoE customer', function () {
    $firstLogin = Plan::factory()->create(['hotspot_starts_at_first_login' => true]);

    app(SwitchPlan::class)->handle($this->account->id, $firstLogin->id, null);
})->throws(FirstLoginPlanNeedsHotspot::class);

it('opens Switch plan for an expired customer and the paid Change plan for one with time left', function () {
    Livewire::test('pages::customers.plan-card', ['accountId' => $this->account->id])
        ->call('openChangePlan')
        ->assertDispatched('modal-open', name: 'switch-plan');

    $this->account->update(['expiry' => '2026-10-20 00:00:00']);

    Livewire::test('pages::customers.plan-card', ['accountId' => $this->account->id])
        ->call('openChangePlan')
        ->assertDispatched('modal-open', name: 'change-plan');
});

it('switches from the plan card and the next Recharge offers the new plan', function () {
    $card = Livewire::test('pages::customers.plan-card', ['accountId' => $this->account->id])->call('openChangePlan');

    expect(array_keys($card->instance()->switchPlans))->toBe([$this->new->id]);

    $card->set('switch_plan_id', (string) $this->old->id)
        ->call('switchPlan')
        ->assertHasErrors(['switch_plan_id'])
        ->set('switch_plan_id', (string) $this->new->id)
        ->call('switchPlan')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'switch-plan')
        ->assertDispatched('toast', message: 'Plan switched to Fiber 100.');

    Livewire::test('pages::customers.show', ['account' => $this->account->id])
        ->call('openRecharge')
        ->assertSet('recharge_plan_id', (string) $this->new->id);
});

it('needs the Recharge box', function () {
    $this->actingAs(staffHolding(Permission::ViewCustomers));

    Livewire::test('pages::customers.plan-card', ['accountId' => $this->account->id])
        ->set('switch_plan_id', (string) $this->new->id)
        ->call('switchPlan')
        ->assertForbidden();

    expect($this->account->fresh()->plan_id)->toBe($this->old->id);
});

it('lets a Hotspot customer switch to a first-login plan', function () {
    turnHotspotOn();
    $this->account->update(['service_type' => ServiceType::Hotspot]);
    $firstLogin = Plan::factory()->create(['hotspot_starts_at_first_login' => true]);

    app(SwitchPlan::class)->handle($this->account->id, $firstLogin->id, null);

    expect($this->account->fresh()->plan_id)->toBe($firstLogin->id);
});
