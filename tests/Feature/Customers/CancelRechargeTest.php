<?php

use App\Actions\Customers\CancelRecharge;
use App\Actions\Customers\RechargeAccount;
use App\Billing\ChargeOptions;
use App\Billing\PaymentInput;
use App\Enums\AccountStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Exceptions\CannotCancelRecharge;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Radacct;
use App\Models\Recharge;
use App\Models\User;
use App\Support\ActivityTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 14:30:00'));
    $this->staff = User::factory()->create();
    $this->actingAs($this->staff);
    $this->old = Plan::factory()->create(['slug' => 'home', 'name' => 'Home 25', 'price' => 500]);
    $this->wrong = Plan::factory()->create(['slug' => 'fiber', 'name' => 'Fiber 100', 'price' => 900]);
    $this->account = Account::factory()->create([
        'username' => 'alice', 'plan_id' => $this->old->id, 'expiry' => '2026-10-01 00:00:00',
        'status_id' => AccountStatus::Inactive, 'usage_since' => '2026-09-01 00:00:00',
    ]);
});

function rechargeAlice(Plan $plan, string $expiry = '2026-11-09 14:30', ?ChargeOptions $charge = null): Recharge
{
    return app(RechargeAccount::class)->handle(test()->account->id, $plan->id, Carbon::parse($expiry), (string) $plan->price, null, charge: $charge ?? new ChargeOptions);
}

it('puts an expired customer back where they were before a wrong renewal', function () {
    turnInvoicingOn();
    $recharge = rechargeAlice($this->wrong);
    $this->travel(5)->minutes();

    $disconnect = app(CancelRecharge::class)->handle($recharge->id, 'wrong plan');

    expect($disconnect)->toBeTrue()
        ->and($this->account->fresh())
        ->expiry->format('Y-m-d H:i')->toBe('2026-10-01 00:00')
        ->plan_id->toBe($this->old->id)
        ->status_id->toBe(AccountStatus::Inactive)
        ->usage_since->format('Y-m-d H:i')->toBe('2026-10-10 14:30')
        ->and($recharge->invoice->fresh())
        ->status->toBe(InvoiceStatus::Cancelled)
        ->cancel_reason->toBe('Recharge cancelled: wrong plan')
        ->and($recharge->fresh())
        ->cancelled_at->not->toBeNull()
        ->cancelled_by->toBe($this->staff->id)
        ->cancel_reason->toBe('wrong plan')
        ->and(DB::table('radcheck')->where('username', 'alice')->where('attribute', 'Expiration')->value('value'))->toBe('01 Oct 2026 00:00')
        ->and(DB::table('radusergroup')->where('username', 'alice')->value('groupname'))->toBe('home');

    $entry = Activity::query()->where('event', 'recharge-cancelled')->sole();
    expect($entry->attribute_changes['old'])->toBe(['plan' => 'Fiber 100', 'expiry' => '2026-11-09 14:30'])
        ->and($entry->attribute_changes['attributes'])->toBe(['plan' => 'Home 25', 'expiry' => '2026-10-01 00:00', 'reason' => 'wrong plan']);
});

it('needs no disconnect for an early renewal of the same plan', function () {
    $this->account->update(['expiry' => '2026-10-20 00:00:00', 'status_id' => AccountStatus::Active]);
    $recharge = rechargeAlice($this->old, '2026-11-19 00:00');

    expect(app(CancelRecharge::class)->handle($recharge->id, 'paid twice'))->toBeFalse()
        ->and($this->account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-10-20 00:00')
        ->and($this->account->fresh()->status_id)->toBe(AccountStatus::Active);
});

it('refuses and changes nothing', function (Closure $setup, string $why) {
    turnInvoicingOn();
    $recharge = $setup();
    $expiry = $this->account->fresh()->expiry;

    try {
        app(CancelRecharge::class)->handle($recharge->id, 'wrong');
        $this->fail('Cancel recharge was not refused.');
    } catch (CannotCancelRecharge $refused) {
        expect($refused->why)->toBe($why);
    }

    expect($this->account->fresh()->expiry->equalTo($expiry))->toBeTrue()
        ->and($recharge->fresh()->cancelled_at)->toBeNull()
        ->and(Invoice::query()->where('status', InvoiceStatus::Cancelled)->count())->toBe(0);
})->with([
    'not the newest' => [function () {
        $first = rechargeAlice(test()->wrong);
        rechargeAlice(test()->old, '2026-12-09 14:30');

        return $first;
    }, 'not-latest'],
    'invoice with a payment' => [function () {
        $cash = PaymentMethod::query()->where('name', 'Cash')->sole();

        return rechargeAlice(test()->wrong, charge: new ChargeOptions(payment: new PaymentInput($cash->id, '100', null, now(), null)));
    }, 'payments'],
    'advance renewal waiting' => [function () {
        $recharge = rechargeAlice(test()->wrong);
        AdvanceRenewal::factory()->for(test()->account)->create();

        return $recharge;
    }, 'advance-waiting'],
    'made before this update' => [function () {
        $recharge = rechargeAlice(test()->wrong);
        $recharge->update(['old_status' => null]);

        return $recharge;
    }, 'too-old'],
]);

it('refuses a recharge that is already cancelled', function () {
    $recharge = rechargeAlice($this->wrong);
    app(CancelRecharge::class)->handle($recharge->id, 'wrong');

    app(CancelRecharge::class)->handle($recharge->id, 'again');
})->throws(CannotCancelRecharge::class);

it('keeps a customer Suspended after the recharge Suspended', function () {
    $recharge = rechargeAlice($this->wrong);
    $this->account->update(['status_id' => AccountStatus::Suspended]);

    app(CancelRecharge::class)->handle($recharge->id, 'wrong');

    expect($this->account->fresh()->status_id)->toBe(AccountStatus::Suspended);
});

it('puts back a first-login validity that was waiting', function () {
    turnHotspotOn();
    $account = Account::factory()->hotspotPending(180)->create();
    $recharge = app(RechargeAccount::class)->handle($account->id, Plan::factory()->hours(3)->firstLogin()->create()->id, null, '20', null);

    app(CancelRecharge::class)->handle($recharge->id, 'sold twice');

    expect($account->fresh()->hotspot_pending_validity)->toBe(180);
});

it('records what the recharge changed', function () {
    $recharge = rechargeAlice($this->wrong);

    expect($recharge->fresh())
        ->old_plan_id->toBe($this->old->id)
        ->old_status->toBe(AccountStatus::Inactive)
        ->old_pending_validity->toBeNull();
});

it('offers Cancel recharge on the newest row only, to staff with Cancel recharges', function () {
    $first = rechargeAlice($this->old, '2026-11-09 14:30');
    $newest = rechargeAlice($this->wrong, '2026-12-09 14:30');
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::CancelRecharges));

    Livewire::test('pages::customers.show', ['account' => $this->account->id, 'tab' => 'recharges'])
        ->assertSeeHtml('wire:click="openCancelRecharge('.$newest->id.')"')
        ->assertDontSeeHtml('wire:click="openCancelRecharge('.$first->id.')"');

    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::RechargeCustomers));

    Livewire::test('pages::customers.show', ['account' => $this->account->id, 'tab' => 'recharges'])
        ->assertDontSeeHtml('openCancelRecharge')
        ->call('openCancelRecharge', $newest->id)
        ->assertForbidden();
});

it('cancels from the Recharges tab with a reason and disconnects an online customer', function () {
    Queue::fake();
    $recharge = rechargeAlice($this->wrong);
    Radacct::factory()->create(['username' => 'alice']);
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::CancelRecharges));

    Livewire::test('pages::customers.show', ['account' => $this->account->id, 'tab' => 'recharges'])
        ->call('openCancelRecharge', $recharge->id)
        ->assertDispatched('modal-open', name: 'cancel-recharge')
        ->call('cancelRecharge')
        ->assertHasErrors(['cancel_recharge_reason' => 'required'])
        ->set('cancel_recharge_reason', 'Wrong plan')
        ->call('cancelRecharge')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Recharge cancelled. The expiry is back to 01/10/2026 00:00. They are disconnected so the change applies now.')
        ->assertSee('Cancelled');

    Queue::assertPushed(DisconnectAccount::class);
    expect($recharge->fresh()->cancel_reason)->toBe('Wrong plan');
});

it('tells staff why a recharge cannot be cancelled', function (Closure $setup, string $message) {
    turnInvoicingOn();
    $recharge = $setup();
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::CancelRecharges));

    Livewire::test('pages::customers.show', ['account' => $this->account->id, 'tab' => 'recharges'])
        ->call('openCancelRecharge', $recharge->id)
        ->set('cancel_recharge_reason', 'Wrong')
        ->call('cancelRecharge')
        ->assertHasErrors(['cancel_recharge_reason'])
        ->assertSee($message);
})->with([
    'not the newest' => [function () {
        $first = rechargeAlice(test()->wrong);
        rechargeAlice(test()->old, '2026-12-09 14:30');

        return $first;
    }, 'Only the latest recharge can be cancelled.'],
    'payments' => [fn () => rechargeAlice(test()->wrong, charge: new ChargeOptions(payment: new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, '100', null, now(), null))),
        'Its invoice has payments. Remove them first.'],
    'advance renewal' => [function () {
        $recharge = rechargeAlice(test()->wrong);
        AdvanceRenewal::factory()->for(test()->account)->create();

        return $recharge;
    }, 'Cancel the advance renewal first.'],
    'too old' => [function () {
        $recharge = rechargeAlice(test()->wrong);
        $recharge->update(['old_status' => null]);

        return $recharge;
    }, 'This recharge was made before cancelling was possible.'],
]);

it('writes the cancel in the timeline', function () {
    AppSetting::put('date_format', 'd/m/Y');
    $recharge = rechargeAlice($this->wrong);
    app(CancelRecharge::class)->handle($recharge->id, 'wrong plan');

    $entry = ActivityTimeline::entry(Activity::query()->where('event', 'recharge-cancelled')->sole(), 'd/m/Y');

    expect($entry['sentence'])->toBe('Recharge cancelled: expiry back to 01/10/2026 00:00, plan Home 25.')
        ->and($entry['tone'])->toBe('red')
        ->and($entry['icon'])->toBe('no-symbol');
});

it('turns auto renew off when the restored expiry would renew the customer again at once', function () {
    $this->account->update(['status_id' => AccountStatus::Active, 'auto_renew' => true, 'expiry' => '2026-10-10 14:35:00']);
    $recharge = rechargeAlice($this->old, '2026-11-10 14:35');
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::CancelRecharges));

    Livewire::test('pages::customers.show', ['account' => $this->account->id, 'tab' => 'recharges'])
        ->call('openCancelRecharge', $recharge->id)
        ->set('cancel_recharge_reason', 'Customer left')
        ->call('cancelRecharge')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Recharge cancelled. The expiry is back to 10/10/2026 14:35. Auto renew is off now, so it does not renew them again.');

    $this->artisan('customers:renewals')->assertSuccessful();

    expect($this->account->fresh())->auto_renew->toBeFalse()->expiry->format('Y-m-d H:i')->toBe('2026-10-10 14:35')
        ->and(Recharge::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'recharge-cancelled')->sole()->attribute_changes['attributes']['auto_renew'])->toBe('off');
});

it('keeps auto renew on when the restored expiry is still far off', function () {
    $this->account->update(['status_id' => AccountStatus::Active, 'auto_renew' => true, 'expiry' => '2026-10-20 00:00:00']);
    $recharge = rechargeAlice($this->old, '2026-11-19 00:00');

    app(CancelRecharge::class)->handle($recharge->id, 'paid twice');

    expect($this->account->fresh()->auto_renew)->toBeTrue();
});

it('refuses to put the customer back on a plan deleted since', function () {
    $recharge = rechargeAlice($this->wrong);
    $this->old->delete();

    try {
        app(CancelRecharge::class)->handle($recharge->id, 'wrong');
        $this->fail('Cancel recharge was not refused.');
    } catch (CannotCancelRecharge $refused) {
        expect($refused->why)->toBe('plan-deleted');
    }

    expect($this->account->fresh()->plan_id)->toBe($this->wrong->id)
        ->and($recharge->fresh()->cancelled_at)->toBeNull();
});

it('shows why a recharge onto a deleted plan cannot be cancelled', function () {
    $recharge = rechargeAlice($this->wrong);
    $this->old->delete();
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::CancelRecharges));

    Livewire::test('pages::customers.show', ['account' => $this->account->id, 'tab' => 'recharges'])
        ->call('openCancelRecharge', $recharge->id)
        ->set('cancel_recharge_reason', 'Wrong')
        ->call('cancelRecharge')
        ->assertHasErrors(['cancel_recharge_reason'])
        ->assertSee('The plan before this recharge was deleted. Use Change plan and Change expiry instead.');
});
