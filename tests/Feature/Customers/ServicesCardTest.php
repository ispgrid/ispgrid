<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Customers\AddCustomerService;
use App\Actions\Customers\RenewCustomerService;
use App\Billing\PaymentInput;
use App\Enums\DisconnectReason;
use App\Enums\Permission;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\CustomerService;
use App\Models\Invoice;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Item;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-14 10:00:00'));
    $this->actingAs(User::factory()->create());
    turnInvoicingOn();
    Queue::fake();
    $this->ip = Ip::factory()->for(IpRange::factory()->pppoe(), 'range')->create(['address' => '10.0.0.5']);
    $this->staticIp = Item::factory()->staticIp()->create(['price' => 300]);
    $this->account = Account::factory()->create(['username' => 'alice']);
});

function servicesCard(Account $account)
{
    return Livewire::test('pages::customers.services-card', ['accountId' => $account->id]);
}

function goOnline(string $username): void
{
    DB::table('radacct')->insert(['acctsessionid' => 's-'.$username, 'acctuniqueid' => 'u-'.$username, 'username' => $username, 'nasipaddress' => '10.0.0.1', 'acctstarttime' => now()]);
}

it('adds a static ip service from the card with a payment', function () {
    servicesCard($this->account)
        ->call('openAdd')
        ->set('item_id', (string) $this->staticIp->id)
        ->assertSet('amount', '300')
        ->set('months', '3')
        ->assertSet('amount', '900')
        ->set('ip_address', '10.0.0.5')
        ->set('paid_now', true)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSee('Static IP')
        ->assertSee('10.0.0.5');

    $invoice = Invoice::query()->sole();
    expect($invoice->total)->toBe('1062.00')
        ->and($invoice->paid_total)->toBe('1062.00')
        ->and($this->account->fresh()->ip_id)->toBe($this->ip->id);
});

it('shows the service on the billing tab of the customer page', function () {
    app(AddCustomerService::class)->handle($this->account, $this->staticIp, 1, now(), '300', '10.0.0.5', null);

    $this->get(route('customers.show', ['account' => $this->account->id, 'tab' => 'billing']))
        ->assertOk()->assertSee(__('Services'))->assertSee('10.0.0.5');
});

it('shows the services and the billing tab while invoicing is off', function () {
    AppSetting::put('billing.enabled', '0');

    $this->get(route('customers.show', ['account' => $this->account->id, 'tab' => 'billing']))
        ->assertOk()->assertSee(__('Add service'));
});

it('pre-fills the customer fixed ip for a static ip item', function () {
    $this->account->update(['ip_id' => $this->ip->id]);

    servicesCard($this->account)
        ->call('openAdd')
        ->set('item_id', (string) $this->staticIp->id)
        ->assertSet('ip_address', '10.0.0.5');
});

it('fills the next free ip', function () {
    servicesCard($this->account)
        ->call('openAdd')
        ->set('item_id', (string) $this->staticIp->id)
        ->call('fillNextFreeIp')
        ->assertSet('ip_address', '10.0.0.5');
});

it('lists only active service items', function () {
    Item::factory()->create(['name' => 'Router']);
    Item::factory()->service()->inactive()->create(['name' => 'Old OTT']);
    Item::factory()->service()->create(['name' => 'OTT pack']);

    servicesCard($this->account)
        ->call('openAdd')
        ->assertSee('OTT pack')
        ->assertDontSee('Old OTT')
        ->assertDontSee('Router');
});

it('ignores a typed amount without set customer pricing', function () {
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ManageCustomerServices, Permission::ViewInvoices));

    servicesCard($this->account)
        ->call('openAdd')
        ->set('item_id', (string) $this->staticIp->id)
        ->set('months', '3')
        ->set('ip_address', '10.0.0.5')
        ->set('amount', '1')
        ->set('paid_now', false)
        ->call('add')
        ->assertHasNoErrors();

    expect(Invoice::query()->sole()->taxable_total)->toBe('900.00');
});

it('takes a typed amount with set customer pricing', function () {
    servicesCard($this->account)
        ->call('openAdd')
        ->set('item_id', (string) $this->staticIp->id)
        ->set('ip_address', '10.0.0.5')
        ->set('amount', '250')
        ->set('paid_now', false)
        ->call('add');

    expect(Invoice::query()->sole()->taxable_total)->toBe('250.00');
});

it('shows the list but no buttons to staff without the box and refuses their actions', function () {
    $service = app(AddCustomerService::class)->handle($this->account, $this->staticIp, 1, now(), '300', '10.0.0.5', null);
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices));

    servicesCard($this->account)
        ->assertSee('Static IP')
        ->assertDontSee(__('Add service'))
        ->call('openAdd')->assertForbidden();

    servicesCard($this->account)->call('openEnd', $service->id)->assertForbidden();
});

it('refuses a future start date and a bad ip', function () {
    servicesCard($this->account)
        ->call('openAdd')
        ->set('item_id', (string) $this->staticIp->id)
        ->set('ip_address', '10.0.0.5')
        ->set('start_date', '2026-11-15')
        ->call('add')
        ->assertHasErrors(['start_date'])
        ->set('start_date', '2026-11-14')
        ->set('ip_address', '10.0.0.99')
        ->set('paid_now', false)
        ->call('add')
        ->assertHasErrors(['ip_address']);

    expect(CustomerService::query()->count())->toBe(0);
});

it('renews a service and disconnects a lapsed static ip', function () {
    $service = app(AddCustomerService::class)->handle($this->account, $this->staticIp, 1, now(), '300', '10.0.0.5', null);
    $service->update(['paid_until' => now()->subDay(), 'expired_at' => now()->subDay()]);
    goOnline('alice');

    servicesCard($this->account)
        ->call('openRenew', $service->id)
        ->assertSet('amount', '300')
        ->set('paid_now', false)
        ->call('renew')
        ->assertHasNoErrors();

    expect($service->fresh()->paid_until->toDateTimeString())->toBe('2026-12-14 10:00:00');
    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->reason === DisconnectReason::StaticIpRenewed);
});

it('ends an active static ip and disconnects', function () {
    $service = app(AddCustomerService::class)->handle($this->account, $this->staticIp, 1, now(), '300', '10.0.0.5', null);
    goOnline('alice');

    servicesCard($this->account)
        ->call('openEnd', $service->id)
        ->call('end')->assertHasErrors(['reason'])
        ->set('reason', 'Customer asked')
        ->call('end')->assertHasNoErrors();

    expect($service->fresh()->ended_at)->not->toBeNull()
        ->and($this->ip->fresh()->is_used)->toBeFalse();
    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->reason === DisconnectReason::StaticIpReleased);
});

it('releases a held ip now without a disconnect', function () {
    $service = app(AddCustomerService::class)->handle($this->account, $this->staticIp, 1, now(), '300', '10.0.0.5', null);
    $service->update(['paid_until' => now()->subDay(), 'expired_at' => now()->subDay(), 'held_until' => now()->addDays(29)]);
    goOnline('alice');

    servicesCard($this->account)->assertSee(__('Expired, held until :date', ['date' => '13/12/2026']))
        ->call('releaseIp', $service->id);

    expect($service->fresh()->ended_at)->not->toBeNull()
        ->and($this->account->fresh()->ip_id)->toBeNull();
    Queue::assertNothingPushed();
});

it('refuses release on an active service', function () {
    $service = app(AddCustomerService::class)->handle($this->account, $this->staticIp, 1, now(), '300', '10.0.0.5', null);

    servicesCard($this->account)->call('releaseIp', $service->id);

    expect($service->fresh()->ended_at)->toBeNull();
});

it('says why a renewal with payments cannot be cancelled', function () {
    $service = app(AddCustomerService::class)->handle($this->account, $this->staticIp, 1, now(), '300', '10.0.0.5', null);
    app(RenewCustomerService::class)->handle($service, 1, '300', null);
    app(RecordPayment::class)->handle($service->renewals()->latest('id')->first()->invoice_id,
        new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, '100', null, now(), null));

    servicesCard($this->account)
        ->call('openCancelRenewal', $service->id)
        ->set('reason', 'Wrong')
        ->call('cancelRenewal')
        ->assertHasErrors(['reason'])
        ->assertSee('Its invoice has payments. Remove them first.');
});

it('cancels the latest renewal from the card', function () {
    $service = app(AddCustomerService::class)->handle($this->account, $this->staticIp, 1, now(), '300', '10.0.0.5', null);
    app(RenewCustomerService::class)->handle($service, 1, '300', null);

    servicesCard($this->account)
        ->call('openCancelRenewal', $service->id)
        ->set('reason', 'Wrong months')
        ->call('cancelRenewal')
        ->assertHasNoErrors();

    expect($service->fresh()->paid_until->toDateTimeString())->toBe('2026-12-14 10:00:00');
});

it('hides ended services until asked', function () {
    CustomerService::factory()->for($this->account)->for(Item::factory()->service()->inactive()->state(['name' => 'Gone OTT']))->create(['ended_at' => now()]);

    servicesCard($this->account)
        ->assertDontSee('Gone OTT')
        ->set('show_ended', true)
        ->assertSee('Gone OTT');
});

it('refuses actions on a deleted customer', function () {
    $this->account->delete();

    servicesCard($this->account)->assertDontSee(__('Add service'))->call('openAdd')->assertForbidden();
});
