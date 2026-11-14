<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\ReissueInvoice;
use App\Actions\Customers\AddCustomerService;
use App\Actions\Customers\CancelServiceRenewal;
use App\Actions\Customers\RenewCustomerService;
use App\Billing\LineInput;
use App\Billing\PaymentInput;
use App\Enums\InvoiceStatus;
use App\Exceptions\CannotCancelServiceRenewal;
use App\Models\Account;
use App\Models\CustomerService;
use App\Models\CustomerServiceRenewal;
use App\Models\Ip;
use App\Models\Item;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-14 10:00:00'));
    $this->actingAs(User::factory()->create());
    turnInvoicingOn();
    $this->ip = Ip::factory()->create(['address' => '10.0.0.5']);
    $this->account = Account::factory()->create();
    $this->service = app(AddCustomerService::class)->handle($this->account, Item::factory()->staticIp()->create(), 1, now(), '300', '10.0.0.5', null);
});

function renewService(CustomerService $service, int $months = 1): CustomerServiceRenewal
{
    app(RenewCustomerService::class)->handle($service->fresh(), $months, '300', null);

    return $service->renewals()->latest('id')->first();
}

function cancelRenewal(int $id, string $reason = 'Wrong customer'): bool
{
    return app(CancelServiceRenewal::class)->handle($id, $reason);
}

function staticIpWritten(Account $account): bool
{
    return DB::table('radreply')->where('username', $account->username)->where('attribute', 'Framed-IP-Address')->exists();
}

it('puts paid until back and cancels the invoice of an active renewal', function () {
    $renewal = renewService($this->service, 3);

    expect(cancelRenewal($renewal->id))->toBeFalse();

    $service = $this->service->fresh();
    expect($service->paid_until->toDateTimeString())->toBe('2026-12-14 10:00:00')
        ->and($renewal->fresh()->cancelled_at)->not->toBeNull()
        ->and($renewal->fresh()->cancel_reason)->toBe('Wrong customer')
        ->and($renewal->invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(staticIpWritten($this->account))->toBeTrue()
        ->and(DB::table('activity_log')->where('event', 'service-renewal-cancelled')->count())->toBe(1);
});

it('refuses with payments on the invoice', function () {
    $renewal = renewService($this->service);
    app(RecordPayment::class)->handle($renewal->invoice_id, new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, '100', null, now(), null));

    expect(fn () => cancelRenewal($renewal->id))->toThrow(fn (CannotCancelServiceRenewal $e) => expect($e->why)->toBe('payments'))
        ->and($renewal->fresh()->cancelled_at)->toBeNull()
        ->and($this->service->fresh()->paid_until->toDateTimeString())->toBe('2027-01-14 10:00:00');
});

it('refuses a renewal that is not the latest', function () {
    $first = renewService($this->service);
    renewService($this->service);

    expect(fn () => cancelRenewal($first->id))->toThrow(fn (CannotCancelServiceRenewal $e) => expect($e->why)->toBe('not-latest'));
});

it('refuses the add row', function () {
    expect(fn () => cancelRenewal($this->service->renewals()->sole()->id))->toThrow(fn (CannotCancelServiceRenewal $e) => expect($e->why)->toBe('first'));
});

it('drops the ip at once and holds it from now when a cancel puts paid until in the past', function () {
    $this->travelTo(Carbon::parse('2026-12-20 10:00:00'));
    $this->artisan('customers:renewals');
    expect($this->service->fresh()->expired_at)->not->toBeNull();

    $renewal = renewService($this->service);
    expect(staticIpWritten($this->account))->toBeTrue();

    $this->travelTo(Carbon::parse('2026-12-21 10:00:00'));
    expect(cancelRenewal($renewal->id))->toBeTrue();

    $service = $this->service->fresh();
    expect($service->paid_until->toDateTimeString())->toBe('2026-12-14 10:00:00')
        ->and($service->held_until->toDateTimeString())->toBe('2027-01-20 10:00:00')
        ->and($service->expired_at)->not->toBeNull()
        ->and($service->ended_at)->toBeNull()
        ->and(staticIpWritten($this->account))->toBeFalse()
        ->and($this->account->fresh()->ip_id)->toBe($this->ip->id);
});

it('ends the services and frees the ip when the customer is deleted', function () {
    $plain = CustomerService::factory()->for($this->account)->create();

    Livewire::test('pages::customers.show', ['account' => $this->account->id])->call('delete');

    expect($this->service->fresh()->ended_at)->not->toBeNull()
        ->and($this->service->fresh()->end_reason)->toBe('Customer deleted')
        ->and($plain->fresh()->ended_at)->not->toBeNull()
        ->and($this->ip->fresh()->is_used)->toBeFalse();

    $this->account->fresh()->restore();
    expect($this->service->fresh()->ended_at)->not->toBeNull();
});

it('keeps the service link when a service invoice is reissued', function () {
    $renewal = renewService($this->service);
    $old = $renewal->invoice;

    $new = app(ReissueInvoice::class)->handle($old->id, [new LineInput('Static IP 10.0.0.5', null, null, '1', '250', '0', true)],
        false, now(), 'Wrong price');

    expect($new->customer_service_id)->toBe($this->service->id)
        ->and($renewal->fresh()->invoice_id)->toBe($new->id)
        ->and($renewal->fresh()->amount)->toBe($new->total)
        ->and($old->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});
