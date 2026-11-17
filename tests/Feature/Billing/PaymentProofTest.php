<?php

use App\Actions\Billing\IssueInvoice;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('billing');
    turnInvoicingOn();
    $this->account = Account::factory()->create();
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
