<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\RemovePayment;
use App\Billing\PaymentInput;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Support\ActivityFormatter;
use App\Support\ActivityTimeline;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    AppSetting::put('date_format', 'd/m/Y');
    turnInvoicingOn();
    $this->account = Account::factory()->create(['username' => 'alice']);
    $invoice = app(IssueInvoice::class)->handle($this->account, billingLines('500'), false, InvoiceKind::Manual, now());
    $upi = PaymentMethod::query()->where('name', 'UPI')->sole();
    $this->cash = PaymentMethod::query()->where('name', 'Cash')->sole();
    $this->payment = app(RecordPayment::class)->handle($invoice->id, new PaymentInput($upi->id, '300', 'UPI-1', now(), null));
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices, Permission::RecordPayments));
});

it('changes the method, reference and received at and logs what changed', function () {
    Livewire::test('pages::payments.show', ['payment' => $this->payment->id])
        ->assertSee(__('Edit'))
        ->call('openEditPayment')
        ->assertSet('edit_method_id', (string) $this->payment->payment_method_id)
        ->assertSet('edit_reference', 'UPI-1')
        ->assertSet('edit_paid_at', '2026-10-21T10:00')
        ->set('edit_method_id', (string) $this->cash->id)
        ->set('edit_reference', ' CHQ-9 ')
        ->set('edit_paid_at', '2026-10-20T18:30')
        ->call('editPayment')
        ->assertHasNoErrors()
        ->assertSee('CHQ-9');

    $payment = $this->payment->fresh();
    $entry = Activity::query()->where('event', 'payment-edited')->sole();

    expect($payment)
        ->payment_method_id->toBe($this->cash->id)
        ->reference->toBe('CHQ-9')
        ->amount->toBe('300.00')
        ->and($payment->paid_at->format('Y-m-d H:i'))->toBe('2026-10-20 18:30')
        ->and($entry->subject_id)->toBe($this->account->id)
        ->and(ActivityFormatter::changes($entry))->toEqualCanonicalizing([
            ['field' => 'Method', 'old' => 'UPI', 'new' => 'Cash'],
            ['field' => 'Reference', 'old' => 'UPI-1', 'new' => 'CHQ-9'],
            ['field' => 'Received at', 'old' => '21/10/2026 10:00', 'new' => '20/10/2026 18:30'],
        ])
        ->and(ActivityTimeline::entry($entry, 'd/m/Y'))
        ->sentence->toBe("Payment RCPT-{$payment->id} on invoice INV-1 changed.")
        ->changes->toHaveCount(3);
});

it('logs only the fields that changed, and nothing when none did', function () {
    $page = Livewire::test('pages::payments.show', ['payment' => $this->payment->id])
        ->call('openEditPayment')
        ->set('edit_reference', '')
        ->call('editPayment')
        ->assertHasNoErrors();

    expect(ActivityFormatter::changes(Activity::query()->where('event', 'payment-edited')->sole()))
        ->toBe([['field' => 'Reference', 'old' => 'UPI-1', 'new' => null]])
        ->and($this->payment->fresh()->reference)->toBeNull();

    $page->call('openEditPayment')->call('editPayment')->assertHasNoErrors();

    expect(Activity::query()->where('event', 'payment-edited')->count())->toBe(1);
});

it('refuses a future time and a turned-off method, but keeps the current method after it is turned off', function () {
    $this->cash->update(['active' => false]);

    Livewire::test('pages::payments.show', ['payment' => $this->payment->id])
        ->call('openEditPayment')
        ->set('edit_paid_at', '2026-10-21T11:00')
        ->set('edit_method_id', (string) $this->cash->id)
        ->call('editPayment')
        ->assertHasErrors(['edit_paid_at', 'edit_method_id']);

    PaymentMethod::query()->whereKey($this->payment->payment_method_id)->update(['active' => false]);

    Livewire::test('pages::payments.show', ['payment' => $this->payment->id])
        ->call('openEditPayment')
        ->assertSee('UPI')
        ->set('edit_reference', 'UPI-2')
        ->call('editPayment')
        ->assertHasNoErrors();

    expect($this->payment->fresh()->reference)->toBe('UPI-2');
});

it('allows no edit without Record payments, on a removed payment or for a deleted customer', function () {
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices));
    Livewire::test('pages::payments.show', ['payment' => $this->payment->id])
        ->assertDontSee(__('Edit'))
        ->call('openEditPayment')
        ->assertForbidden();

    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices, Permission::RecordPayments));
    $page = Livewire::test('pages::payments.show', ['payment' => $this->payment->id])->call('openEditPayment');
    $this->account->delete();
    $page->call('editPayment')->assertForbidden();

    $this->account->restore();
    app(RemovePayment::class)->handle($this->payment->id, 'Bounced');
    Livewire::test('pages::payments.show', ['payment' => $this->payment->id])
        ->assertDontSee(__('Edit'))
        ->call('openEditPayment')
        ->assertForbidden();

    expect(Activity::query()->where('event', 'payment-edited')->exists())->toBeFalse()
        ->and(Payment::withTrashed()->findOrFail($this->payment->id)->reference)->toBe('UPI-1');
});
