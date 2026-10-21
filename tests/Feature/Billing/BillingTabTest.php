<?php

use App\Actions\Billing\IssueInvoice;
use App\Billing\PaymentInput;
use App\Billing\Statement;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Recharge;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    AppSetting::put('date_format', 'd/m/Y');
    turnInvoicingOn();
    $this->account = Account::factory()->create();
    $this->actingAs(staffHolding(...Permission::cases()));
});

function issueFor(Account $account, string $price, ?string $paid = null): Invoice
{
    $payment = $paid === null ? null : new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, $paid, null, now(), null);

    return app(IssueInvoice::class)->handle($account, billingLines($price), false, InvoiceKind::Manual, now(), $payment);
}

function billingTab(Account $account)
{
    return Livewire::test('pages::customers.billing-tab', ['accountId' => $account->id]);
}

it('shows dues and the statement', function () {
    issueFor($this->account, '500', '590');
    $this->travel(1)->day();
    issueFor($this->account, '8000');

    expect(Statement::dues($this->account))->toBe('9440.00')
        ->and(array_column(Statement::for($this->account), 'balance'))->toBe(['590.00', '0.00', '9440.00']);

    billingTab($this->account)
        ->assertSee('9,440.00')
        ->assertSee('INV-1')
        ->assertSee('INV-2')
        ->assertSee('Unpaid');
});

it('creates a manual invoice for a CPE', function () {
    billingTab($this->account)
        ->call('openNewInvoice')
        ->set('lines.0.description', 'ONU device')
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '1500')
        ->assertSee('1,770.00')
        ->set('paid_now', false)
        ->call('createInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->sole();
    expect($invoice)
        ->kind->toBe(InvoiceKind::Manual)
        ->total->toBe('1770.00')
        ->status->toBe(InvoiceStatus::Unpaid)
        ->and($invoice->lines()->sole())
        ->description->toBe('ONU device')
        ->item_code->toBe('998422');
});

it('adds and removes lines, and ignores a line discount without Set customer pricing', function () {
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices, Permission::CreateInvoices));

    billingTab($this->account)
        ->call('openNewInvoice')
        ->call('addLine')
        ->call('addLine')
        ->call('removeLine', 2)
        ->set('lines.0.description', 'ONU device')
        ->set('lines.0.unit_price', '1000')
        ->set('lines.0.discount', '50')
        ->set('lines.1.description', 'Fitting')
        ->set('lines.1.unit_price', '200')
        ->set('lines.1.taxed', false)
        ->set('paid_now', false)
        ->call('createInvoice')
        ->assertHasNoErrors();

    expect(Invoice::query()->sole()->total)->toBe('1380.00');
});

it('back-dates a manual invoice without limit but not into the future', function () {
    $tab = billingTab($this->account)
        ->call('openNewInvoice')
        ->set('lines.0.description', 'ONU device')
        ->set('lines.0.unit_price', '1500')
        ->set('paid_now', false)
        ->set('invoice_date', '2026-10-22')
        ->call('createInvoice')
        ->assertHasErrors('invoice_date');

    $tab->set('invoice_date', '2019-04-02')->call('createInvoice')->assertHasNoErrors();

    expect(Invoice::query()->sole()->issue_date->format('Y-m-d'))->toBe('2019-04-02');
});

it('records a later payment', function () {
    $invoice = issueFor($this->account, '500');

    billingTab($this->account)
        ->call('openRecordPayment', $invoice->id)
        ->assertSet('payment_amount', '')
        ->call('recordPayment')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('removes a payment and cancels an invoice, each with a reason', function () {
    $paid = issueFor($this->account, '500', '590');
    $open = issueFor($this->account, '100');
    $payment = Payment::query()->sole();

    billingTab($this->account)
        ->call('openRemovePayment', $payment->id)
        ->call('removePayment')
        ->assertHasErrors('remove_reason')
        ->set('remove_reason', 'Cheque bounced')
        ->call('removePayment')
        ->assertHasNoErrors()
        ->call('openCancelInvoice', $open->id)
        ->set('cancel_reason', 'Wrong customer')
        ->call('cancelInvoice')
        ->assertHasNoErrors();

    expect($paid->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($open->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(Payment::withTrashed()->sole()->remove_reason)->toBe('Cheque bounced');
});

it('refuses actions on another customer\'s invoice', function () {
    $other = issueFor(Account::factory()->create(), '500');

    billingTab($this->account)->call('openRecordPayment', $other->id)->assertNotFound();
});

it('hides the tab without View invoices', function () {
    $this->actingAs(staffHolding(Permission::ViewCustomers));

    Livewire::test('pages::customers.show', ['account' => $this->account->id])
        ->assertDontSee(__('Billing'));
    billingTab($this->account)->assertForbidden();
});

it('hides the tab when invoicing is off and the customer has no invoices', function () {
    issueFor($this->account, '500');
    AppSetting::put('billing.enabled', '0');
    $without = Account::factory()->create();

    expect(Livewire::test('pages::customers.show', ['account' => $without->id])->instance()->tabs)->not->toHaveKey('billing')
        ->and(Livewire::test('pages::customers.show', ['account' => $this->account->id])->instance()->tabs)->toHaveKey('billing');
});

it('is read-only for a deleted customer', function () {
    $invoice = issueFor($this->account, '500');
    $this->account->delete();

    billingTab($this->account)
        ->assertSee('INV-1')
        ->assertDontSee(__('New invoice'))
        ->call('openNewInvoice')
        ->assertForbidden();

    billingTab($this->account)->call('openRecordPayment', $invoice->id)->assertForbidden();
});

it('links each recharge to its invoice', function () {
    $plan = Plan::factory()->create(['price' => 500]);
    $recharge = Recharge::factory()->create(['account_id' => $this->account->id, 'plan_id' => $plan->id]);
    $invoice = issueFor($this->account, '500');
    $recharge->update(['invoice_id' => $invoice->id]);

    Livewire::withQueryParams(['tab' => 'recharges'])->test('pages::customers.show', ['account' => $this->account->id])
        ->assertSee('INV-1');
});

it('shows dues on the Plan card', function () {
    issueFor($this->account, '500');

    Livewire::test('pages::customers.plan-card', ['accountId' => $this->account->id])
        ->assertSee(__('Dues'))
        ->assertSee('590.00');
});

it('takes no new payment once invoicing is off, but still lets staff correct mistakes', function () {
    $paid = issueFor($this->account, '500', '590');
    $open = issueFor($this->account, '100');
    AppSetting::put('billing.enabled', '0');

    billingTab($this->account)
        ->assertSee('INV-1')
        ->assertDontSee(__('Record payment'))
        ->call('openRecordPayment', $open->id)
        ->assertForbidden();

    billingTab($this->account)
        ->call('openRemovePayment', $paid->payments()->sole()->id)
        ->set('remove_reason', 'Entered twice')
        ->call('removePayment')
        ->assertHasNoErrors();

    Livewire::test('pages::invoices.show', ['invoice' => $open->id])->call('openRecordPayment', $open->id)->assertForbidden();

    expect($paid->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});
