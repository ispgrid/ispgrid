<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Customers\BookAdvanceRenewal;
use App\Actions\Customers\CancelAdvanceRenewal;
use App\Actions\Customers\TerminateAccount;
use App\Billing\ChargeOptions;
use App\Billing\PaymentInput;
use App\Enums\AdvanceRenewalStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Exceptions\RenewalHasPayments;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    $this->actingAs(User::factory()->create());
    $this->plan = Plan::factory()->create(['name' => 'Home 30', 'price' => 500, 'validity' => 30]);
    $this->account = Account::factory()->create(['plan_id' => $this->plan->id, 'expiry' => '2026-10-31 00:00:00']);
});

function bookRenewal(Account $account, Plan $plan, ?ChargeOptions $charge = null): AdvanceRenewal
{
    return app(BookAdvanceRenewal::class)->handle($account->id, $plan->id, Carbon::parse('2026-11-30 00:00'), '500', null, $charge ?? new ChargeOptions);
}

it('invoices an advance renewal when booked', function () {
    turnInvoicingOn();
    $cash = PaymentMethod::query()->where('name', 'Cash')->sole();

    $renewal = bookRenewal($this->account, $this->plan, new ChargeOptions(payment: new PaymentInput($cash->id, '590', null, now(), null)));

    $invoice = Invoice::query()->sole();
    expect($renewal->fresh())
        ->invoice_id->toBe($invoice->id)
        ->amount->toBe('590.00')
        ->and($invoice)
        ->status->toBe(InvoiceStatus::Paid)
        ->period_from->format('Y-m-d H:i')->toBe('2026-10-31 00:00')
        ->period_to->format('Y-m-d H:i')->toBe('2026-11-30 00:00')
        ->and($invoice->lines()->sole()->sub_description)->toStartWith('Charges for period 31 Oct 2026 to 30 Nov 2026');
});

it('does not invoice again when the renewal starts', function () {
    turnInvoicingOn();
    $renewal = bookRenewal($this->account, $this->plan);
    $this->travelTo(Carbon::parse('2026-10-30 23:55:00'));

    $this->artisan('customers:renewals')->assertSuccessful();

    expect(Invoice::query()->count())->toBe(1)
        ->and(Recharge::query()->sole())
        ->invoice_id->toBe($renewal->invoice_id)
        ->amount->toBe('590.00');
});

it('does not invoice at the start a renewal booked while invoicing was off', function () {
    bookRenewal($this->account, $this->plan);
    turnInvoicingOn();
    $this->travelTo(Carbon::parse('2026-10-30 23:55:00'));

    $this->artisan('customers:renewals')->assertSuccessful();

    expect(Invoice::query()->count())->toBe(0)
        ->and(Recharge::query()->sole()->amount)->toBe('500.00');
});

it('cancelling an unpaid renewal cancels its invoice', function () {
    turnInvoicingOn();
    $renewal = bookRenewal($this->account, $this->plan);

    app(CancelAdvanceRenewal::class)->handle($this->account->id);

    expect($renewal->fresh()->status)->toBe(AdvanceRenewalStatus::Cancelled)
        ->and(Invoice::query()->sole())
        ->status->toBe(InvoiceStatus::Cancelled)
        ->cancel_reason->toBe('Advance renewal cancelled');
});

it('refuses to cancel a renewal that has payments', function () {
    turnInvoicingOn();
    $renewal = bookRenewal($this->account, $this->plan);
    app(RecordPayment::class)->handle($renewal->invoice_id, new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, '100', null, now(), null));

    expect(fn () => app(CancelAdvanceRenewal::class)->handle($this->account->id))->toThrow(RenewalHasPayments::class)
        ->and(fn () => app(TerminateAccount::class)->handle($this->account->id, 'Moved away'))->toThrow(RenewalHasPayments::class);

    expect($renewal->fresh()->status)->toBe(AdvanceRenewalStatus::Waiting)
        ->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::PartlyPaid);
});

it('booking an advance renewal for a Free customer makes no invoice', function () {
    turnInvoicingOn();
    $this->account->update(['billing_free' => true]);

    $renewal = bookRenewal($this->account, $this->plan);

    expect($renewal->amount)->toBe('0.00')
        ->and($renewal->invoice_id)->toBeNull()
        ->and(Invoice::query()->count())->toBe(0);
});

it('tells staff on the Plan card to remove the payments first', function () {
    turnInvoicingOn();
    $this->actingAs(staffHolding(...Permission::cases()));
    $renewal = bookRenewal($this->account, $this->plan);
    app(RecordPayment::class)->handle($renewal->invoice_id, new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, '100', null, now(), null));

    Livewire::test('pages::customers.plan-card', ['accountId' => $this->account->id])
        ->call('cancelRenewal')
        ->assertDispatched('toast', message: "Remove this renewal's payments first: there are no refunds yet.", variant: 'danger')
        ->set('terminate_note', 'Moved away')
        ->call('terminate')
        ->assertHasErrors('terminate_note');

    expect($renewal->fresh()->status)->toBe(AdvanceRenewalStatus::Waiting);
});
