<?php

use App\Actions\Customers\AddCustomerService;
use App\Actions\Customers\RenewCustomerService;
use App\Billing\Statement;
use App\Enums\InvoiceKind;
use App\Exceptions\CannotAddService;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\CustomerService;
use App\Models\Invoice;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Item;
use App\Models\User;
use App\Support\CustomerRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-14 10:00:00'));
    $this->actingAs(User::factory()->create());
    turnInvoicingOn();
    $this->range = IpRange::factory()->create();
    $this->ip = Ip::factory()->for($this->range, 'range')->create(['address' => '10.0.0.5']);
    $this->item = Item::factory()->staticIp()->create(['price' => 300]);
    $this->account = Account::factory()->create();
});

function addService(Account $account, Item $item, int $months = 3, ?string $ip = '10.0.0.5', ?string $amount = null, ?Carbon $start = null): CustomerService
{
    return app(AddCustomerService::class)->handle($account, $item, $months, $start ?? now(), $amount ?? (string) ($item->price * $months), $ip, null);
}

function framedIp(Account $account): ?string
{
    return DB::table('radreply')->where('username', $account->username)->where('attribute', 'Framed-IP-Address')->value('value');
}

it('adds a static ip service with its own invoice', function () {
    $service = addService($this->account, $this->item);

    $invoice = Invoice::query()->sole();
    $renewal = $service->renewals()->sole();

    expect($service->paid_until->toDateTimeString())->toBe('2027-02-14 10:00:00')
        ->and($service->ip_id)->toBe($this->ip->id)
        ->and($this->account->fresh()->ip_id)->toBe($this->ip->id)
        ->and($this->ip->fresh()->is_used)->toBeTrue()
        ->and(framedIp($this->account))->toBe('10.0.0.5')
        ->and($invoice->kind)->toBe(InvoiceKind::Service)
        ->and($invoice->customer_service_id)->toBe($service->id)
        ->and($invoice->taxable_total)->toBe('900.00')
        ->and($invoice->lines()->sole()->description)->toBe('Static IP 10.0.0.5')
        ->and($invoice->lines()->sole()->sub_description)->toBe('Charges for period 14 Nov 2026 to 14 Feb 2027')
        ->and($invoice->lines()->sole()->item_id)->toBe($this->item->id)
        ->and($renewal->months)->toBe(3)
        ->and($renewal->amount)->toBe('1062.00')
        ->and($renewal->old_paid_until)->toBeNull()
        ->and($renewal->invoice_id)->toBe($invoice->id);
});

it('offers and takes over the customer existing fixed ip', function () {
    $this->account->update(['ip_id' => $this->ip->id]);
    $this->ip->update(['is_used' => true]);

    expect(CustomerRules::ipError('10.0.0.5', $this->account->id))->toBeNull();

    $service = addService($this->account, $this->item);

    expect($service->ip_id)->toBe($this->ip->id)
        ->and($this->account->fresh()->ip_id)->toBe($this->ip->id)
        ->and($this->ip->fresh()->is_used)->toBeTrue();
});

it('moves the customer to the chosen ip and frees the old fixed ip', function () {
    $old = Ip::factory()->for($this->range, 'range')->create(['address' => '10.0.0.9', 'is_used' => true]);
    $this->account->update(['ip_id' => $old->id]);

    addService($this->account, $this->item);

    expect($this->account->fresh()->ip_id)->toBe($this->ip->id)
        ->and($old->fresh()->is_used)->toBeFalse();
});

it('refuses static ip for ipoe and hotspot customers', function (string $state) {
    $account = Account::factory()->{$state}()->create();

    expect(fn () => addService($account, $this->item))->toThrow(fn (CannotAddService $e) => expect($e->why)->toBe('not-pppoe'));
})->with(['ipoe', 'hotspot']);

it('refuses a second static ip service', function () {
    addService($this->account, $this->item);
    Ip::factory()->for($this->range, 'range')->create(['address' => '10.0.0.6']);

    expect(fn () => addService($this->account, $this->item, ip: '10.0.0.6'))->toThrow(fn (CannotAddService $e) => expect($e->why)->toBe('has-static-ip'));
});

it('refuses a one-time item', function () {
    expect(fn () => addService($this->account, Item::factory()->create(), ip: null))->toThrow(fn (CannotAddService $e) => expect($e->why)->toBe('not-service'));
});

it('allows two plain services of the same item', function () {
    $ott = Item::factory()->service()->create(['name' => 'OTT pack', 'price' => 150]);

    addService($this->account, $ott, 1, null);
    addService($this->account, $ott, 1, null);

    expect($this->account->services()->count())->toBe(2)
        ->and($this->account->fresh()->ip_id)->toBeNull()
        ->and(Invoice::query()->count())->toBe(2);
});

it('refuses an ip held by another customer', function () {
    $other = Account::factory()->create();
    $held = addService($other, $this->item, 1);
    $held->update(['paid_until' => now()->subDays(2), 'expired_at' => now()->subDay(), 'held_until' => now()->addDays(28)]);

    expect(CustomerRules::ipError('10.0.0.5', $this->account->id))->not->toBeNull()
        ->and(fn () => addService($this->account, $this->item))->toThrow(fn (CannotAddService $e) => expect($e->why)->toBe('ip-invalid'));
});

it('refuses a static ip service without a valid address', function (?string $address) {
    expect(fn () => addService($this->account, $this->item, ip: $address))->toThrow(fn (CannotAddService $e) => expect($e->why)->toBe('ip-invalid'));
})->with([null, '', '10.0.0.300', '192.0.2.1']);

it('adds 1 month to 31 jan as end of february', function () {
    $this->travelTo(Carbon::parse('2027-01-31 09:00:00'));

    $service = addService($this->account, $this->item, 1);

    expect($service->paid_until->toDateTimeString())->toBe('2027-02-28 09:00:00');
});

it('starts on an earlier chosen day at the current time of day', function () {
    $service = addService($this->account, $this->item, 1, start: Carbon::parse('2026-11-01'));

    expect($service->paid_until->toDateTimeString())->toBe('2026-12-01 10:00:00');
});

it('renews an active service from its paid until and an expired one from now', function () {
    $ott = Item::factory()->service()->create(['price' => 150]);
    $active = addService($this->account, $ott, 1, null);

    app(RenewCustomerService::class)->handle($active, 3, '450', null);
    expect($active->fresh()->paid_until->toDateTimeString())->toBe('2027-03-14 10:00:00');

    $expired = addService($this->account, $ott, 1, null);
    $expired->update(['paid_until' => now()->subDays(5), 'expired_at' => now()->subDays(5)]);

    app(RenewCustomerService::class)->handle($expired, 1, '150', null);
    $renewal = $expired->renewals()->latest('id')->first();

    expect($expired->fresh()->paid_until->toDateTimeString())->toBe('2026-12-14 10:00:00')
        ->and($expired->fresh()->expired_at)->toBeNull()
        ->and($renewal->old_paid_until->toDateTimeString())->toBe('2026-11-09 10:00:00')
        ->and($renewal->invoice->lines()->sole()->sub_description)->toBe('Charges for period 14 Nov 2026 to 14 Dec 2026');
});

it('refuses to renew an ended service', function () {
    $service = addService($this->account, $this->item, 1);
    $service->update(['ended_at' => now()]);

    app(RenewCustomerService::class)->handle($service, 1, '300', null);
})->throws(InvalidArgumentException::class);

it('records the amount without an invoice when invoicing is off', function () {
    AppSetting::put('billing.enabled', '0');

    $service = addService($this->account, $this->item, 1);

    expect(Invoice::query()->count())->toBe(0)
        ->and($service->renewals()->sole()->amount)->toBe('300.00')
        ->and($service->renewals()->sole()->invoice_id)->toBeNull();
});

it('counts an unpaid service invoice toward the customer dues', function () {
    addService($this->account, $this->item, 1);

    expect(Statement::dues($this->account))->toBe('354.00');

    Livewire::test('pages::customers.index')->set('dues', true)->assertSee($this->account->username);
});

it('tells the caller to disconnect when a lapsed static ip is renewed', function () {
    $service = addService($this->account, $this->item, 1);
    $service->update(['paid_until' => now()->subDays(2), 'expired_at' => now()->subDays(2), 'held_until' => now()->addDays(28)]);

    $disconnect = app(RenewCustomerService::class)->handle($service->fresh(), 1, '300', null);

    expect($disconnect)->toBeTrue()
        ->and($service->fresh()->expired_at)->toBeNull()
        ->and($service->fresh()->held_until)->toBeNull()
        ->and(framedIp($this->account))->toBe('10.0.0.5');
});

it('does not ask for a disconnect when an active service is renewed', function () {
    $service = addService($this->account, $this->item, 1);

    expect(app(RenewCustomerService::class)->handle($service, 1, '300', null))->toBeFalse();
});

it('writes the service to the activity log', function () {
    $service = addService($this->account, $this->item, 1);
    app(RenewCustomerService::class)->handle($service, 1, '300', null);

    expect(DB::table('activity_log')->where('subject_id', $this->account->id)->whereIn('event', ['service-added', 'service-renewed'])->pluck('event')->all())
        ->toBe(['service-added', 'service-renewed']);
});
