<?php

use App\Actions\Billing\IssueInvoice;
use App\Billing\Pdf\ReceiptView;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('billing');
    turnInvoicingOn();
    $this->account = Account::factory()->selfcare()->create();
    $this->invoice = app(IssueInvoice::class)->handle($this->account, billingLines('500'), false, InvoiceKind::Manual, now());
    $this->actingAs(staffHolding(...Permission::cases()));
});

function recordingDialog(Account $account, int $invoiceId)
{
    return Livewire::test('pages::customers.billing-tab', ['accountId' => $account->id])->call('openRecordPayment', $invoiceId);
}

it('stores the screenshot with a manual payment', function () {
    recordingDialog($this->account, $this->invoice->id)
        ->set('payment_proof', UploadedFile::fake()->image('upi.png', 20, 20))
        ->call('recordPayment')
        ->assertHasNoErrors();

    $payment = Payment::query()->sole();

    expect($payment->proof_path)->toBe("payments/{$payment->id}/proof.png");
    Storage::disk('billing')->assertExists($payment->proof_path);
});

it('records the payment without a screenshot', function () {
    recordingDialog($this->account, $this->invoice->id)->call('recordPayment')->assertHasNoErrors();

    expect(Payment::query()->sole()->proof_path)->toBeNull()
        ->and(Storage::disk('billing')->allFiles())->toBeEmpty();
});

it('refuses a file that is not a picture or a PDF', function () {
    recordingDialog($this->account, $this->invoice->id)
        ->set('payment_proof', UploadedFile::fake()->createWithContent('x.png', '<html><script>alert(1)</script></html>'))
        ->call('recordPayment')
        ->assertHasErrors('payment_proof');

    expect(Payment::query()->count())->toBe(0);
});

it('refuses a screenshot over 5 MB', function () {
    recordingDialog($this->account, $this->invoice->id)
        ->set('payment_proof', UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf'))
        ->call('recordPayment')
        ->assertHasErrors('payment_proof');

    expect(Payment::query()->count())->toBe(0);
});

function paymentWithProof(object $test, string $name = 'upi.png'): Payment
{
    recordingDialog($test->account, $test->invoice->id)
        ->set('payment_proof', $name === 'upi.png' ? UploadedFile::fake()->image($name, 20, 20) : UploadedFile::fake()->createWithContent($name, '<html><script>alert(1)</script></html>'))
        ->call('recordPayment');

    return Payment::query()->latest('id')->firstOrFail();
}

it('shows the screenshot to staff who can see invoices', function () {
    $payment = paymentWithProof($this);
    $this->actingAs(staffHolding(Permission::ViewInvoices));

    $this->get(route('payments.proof', $payment))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline; filename=payment-'.$payment->id.'-proof.png');
});

it('forbids the screenshot to staff without view-invoices', function () {
    $payment = paymentWithProof($this);
    $this->actingAs(staffLacking(Permission::ViewInvoices));

    $this->get(route('payments.proof', $payment))->assertForbidden();
});

it('answers 404 when the payment has no screenshot', function () {
    recordingDialog($this->account, $this->invoice->id)->call('recordPayment');

    $this->get(route('payments.proof', Payment::query()->sole()))->assertNotFound();
});

it('sends a guest to the login page', function () {
    $payment = paymentWithProof($this);
    auth()->logout();

    $this->get(route('payments.proof', $payment))->assertRedirect(route('login'));
});

it('downloads instead of showing a file whose bytes are not a picture or PDF', function () {
    $payment = paymentWithProof($this);
    Storage::disk('billing')->put($payment->proof_path, '<html><script>alert(1)</script></html>');

    $response = $this->get(route('payments.proof', $payment))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream');
});

it('adds a screenshot in Edit payment and logs it', function () {
    recordingDialog($this->account, $this->invoice->id)->call('recordPayment');
    $payment = Payment::query()->sole();

    Livewire::test('pages::payments.show', ['payment' => $payment->id])
        ->call('openEditPayment')
        ->set('edit_proof', UploadedFile::fake()->image('cheque.jpg', 20, 20))
        ->call('editPayment')
        ->assertHasNoErrors();

    expect($payment->fresh()->proof_path)->toBe("payments/{$payment->id}/proof.jpg");
    Storage::disk('billing')->assertExists($payment->fresh()->proof_path);
    expect(Activity::query()->where('event', 'payment-edited')->sole()->attribute_changes->get('attributes'))->toBe(['screenshot' => 'added']);
});

it('replaces and removes the screenshot in Edit payment', function () {
    $payment = paymentWithProof($this);
    $old = $payment->proof_path;

    Livewire::test('pages::payments.show', ['payment' => $payment->id])
        ->call('openEditPayment')
        ->set('edit_proof', UploadedFile::fake()->image('new.jpg', 20, 20))
        ->call('editPayment')
        ->assertHasNoErrors();

    $replaced = $payment->fresh()->proof_path;
    expect($replaced)->toBe("payments/{$payment->id}/proof.jpg");
    Storage::disk('billing')->assertMissing($old);
    Storage::disk('billing')->assertExists($replaced);

    Livewire::test('pages::payments.show', ['payment' => $payment->id])
        ->call('openEditPayment')
        ->set('edit_remove_proof', true)
        ->call('editPayment')
        ->assertHasNoErrors();

    expect($payment->fresh()->proof_path)->toBeNull();
    Storage::disk('billing')->assertMissing($replaced);
    expect(Activity::query()->where('event', 'payment-edited')->latest('id')->first()->attribute_changes->get('attributes'))->toBe(['screenshot' => 'removed']);
});

it('keeps the screenshot when Edit payment changes something else', function () {
    $payment = paymentWithProof($this);

    Livewire::test('pages::payments.show', ['payment' => $payment->id])
        ->call('openEditPayment')
        ->set('edit_reference', 'UPI-77')
        ->call('editPayment')
        ->assertHasNoErrors();

    expect($payment->fresh()->proof_path)->not->toBeNull();
    Storage::disk('billing')->assertExists($payment->fresh()->proof_path);
});

it('refuses a disguised upload in Edit payment', function () {
    $payment = paymentWithProof($this);

    Livewire::test('pages::payments.show', ['payment' => $payment->id])
        ->call('openEditPayment')
        ->set('edit_proof', UploadedFile::fake()->createWithContent('x.png', '<svg onload="alert(1)"></svg>'))
        ->call('editPayment')
        ->assertHasErrors('edit_proof');
});

it('does not let staff without record-payments change the screenshot', function () {
    $payment = paymentWithProof($this);
    $this->actingAs(staffLacking(Permission::RecordPayments));

    Livewire::test('pages::payments.show', ['payment' => $payment->id])->call('openEditPayment')->assertForbidden();
});

it('never links the screenshot in Selfcare or on the receipt', function () {
    $payment = Payment::factory()->create(['invoice_id' => $this->invoice->id, 'proof_path' => 'payments/1/proof.png']);
    $receipt = view('pdf.receipt', ['receipt' => ReceiptView::for($payment)])->render();

    expect($receipt)->not->toContain('proof')
        ->and(json_encode(ReceiptView::for($payment)))->not->toContain('proof');

    turnSelfcareOn();
    auth()->logout();
    actingAsCustomer($this->account);
    $this->get(route('selfcare.dashboard'))->assertOk()->assertDontSee('/proof');
});
