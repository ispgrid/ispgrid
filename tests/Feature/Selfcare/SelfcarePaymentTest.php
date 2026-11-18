<?php

use App\Actions\Billing\IssueInvoice;
use App\Enums\InvoiceKind;
use App\Enums\OnlinePaymentOrigin;
use App\Enums\OnlinePaymentStatus;
use App\Http\Middleware\SelfcareSession;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Models\SelfcareAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Fakes\FakeGateway;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-17 10:00:00'));
    turnQuickPayOn('7');
    turnSelfcareOn();
    AppSetting::put('currency_symbol', '₹');
    AppSetting::put('date_format', 'd/m/Y');
    $this->account = Account::factory()->selfcare()->create(['username' => 'kushal', 'fullname' => 'Kushal Example', 'expiry' => now()->addDays(20)]);
    app(IssueInvoice::class)->handle($this->account, billingLines('100'), false, InvoiceKind::Manual, now());
    $this->payment = OnlinePayment::factory()->create([
        'account_id' => $this->account->id, 'origin' => OnlinePaymentOrigin::Selfcare, 'amount' => '118.00', 'dues_amount' => '118.00',
    ]);
});

function selfcareSigned(OnlinePayment $payment): array
{
    return ['order' => $payment->public_id, 'signature' => hash_hmac('sha256', $payment->public_id, 'test-secret')];
}

it('brings a Selfcare payment back to Selfcare', function () {
    FakeGateway::pay($this->payment);

    $this->post(route('pay.return', 'fake'), selfcareSigned($this->payment))
        ->assertRedirect(route('selfcare.payment', $this->payment->public_id));
});

it('shows the owner the result with the receipt', function () {
    FakeGateway::pay($this->payment);
    $this->post(route('pay.return', 'fake'), selfcareSigned($this->payment));
    $receipt = Payment::query()->sole();
    actingAsCustomer($this->account);

    $this->get(route('selfcare.payment', $this->payment->public_id))
        ->assertOk()
        ->assertSee(__('Payment of :amount received. Thank you!', ['amount' => '₹118.00']))
        ->assertSee('RCPT-'.$receipt->id)
        ->assertSee(route('selfcare.receipt', $receipt), false);
});

it('polls only while the payment is pending, without asking the gateway', function () {
    actingAsCustomer($this->account);

    $this->get(route('selfcare.payment', $this->payment->public_id))->assertSee('wire:poll.10s', false);
    expect(FakeGateway::$asked)->toBe([]);

    $this->payment->update(['status' => OnlinePaymentStatus::Failed]);
    $this->get(route('selfcare.payment', $this->payment->public_id))->assertDontSee('wire:poll', false)->assertSee(__('Try again'));
});

it('sends a visitor without a session to the public result page', function () {
    $this->get(route('selfcare.payment', $this->payment->public_id))->assertRedirect(route('pay.result', $this->payment->public_id));
});

it('sends another logged-in customer to the public result page', function () {
    $other = Account::factory()->selfcare()->create(['username' => 'other']);
    actingAsCustomer($other);

    $this->get(route('selfcare.payment', $this->payment->public_id))->assertRedirect(route('pay.result', $this->payment->public_id));
});

it('treats an unknown payment like the public result page does', function () {
    actingAsCustomer($this->account);

    $this->get('/selfcare/payment/NOTAREALID0000000000000000')->assertNotFound();
});

it('still brings a quick pay payment to the public result page', function () {
    $payment = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'amount' => '118.00', 'dues_amount' => '118.00']);
    FakeGateway::pay($payment);

    $this->post(route('pay.return', 'fake'), selfcareSigned($payment))->assertRedirect(route('pay.result', $payment->public_id));
});

it('shows the new expiry for a paid renewal and omits it without one', function () {
    actingAsCustomer($this->account);
    $this->payment->update(['status' => OnlinePaymentStatus::Paid, 'renewal_amount' => '50.00']);
    $date = $this->account->fresh()->expiry->format('d/m/Y H:i');

    $this->get(route('selfcare.payment', $this->payment->public_id))->assertSee(__('Your connection now runs until :date.', ['date' => $date]));

    $this->payment->update(['renewal_amount' => '0.00']);
    $this->get(route('selfcare.payment', $this->payment->public_id))->assertDontSee(__('Your connection now runs until :date.', ['date' => $date]));

    $this->payment->update(['status' => OnlinePaymentStatus::NeedsAttention, 'renewal_amount' => '50.00']);
    $this->get(route('selfcare.payment', $this->payment->public_id))->assertDontSee(__('Your connection now runs until :date.', ['date' => $date]));
});

it('sends an idle session to the public result page on first load', function () {
    actingAsCustomer($this->account);
    session()->put(SelfcareSession::LAST_SEEN, now()->subMinutes(31)->getTimestamp());

    $this->get(route('selfcare.payment', $this->payment->public_id))->assertRedirect(route('pay.result', $this->payment->public_id));
});

it('checks the session again on every poll', function () {
    actingAsCustomer($this->account);
    $page = Livewire::test('pages::selfcare.payment', ['publicId' => $this->payment->public_id])->assertNoRedirect();

    session()->put(SelfcareSession::LAST_SEEN, now()->subMinutes(31)->getTimestamp());

    $page->call('$refresh')->assertRedirect(route('pay.result', $this->payment->public_id));
});

it('treats any connection on the mobile of a mobile login as the owner', function () {
    $this->account->update(['mobile' => '9876543210']);
    $home = Account::factory()->selfcare()->create(['mobile' => '9876543210']);
    $customer = SelfcareAccount::query()->findOrFail($home->id);
    Auth::guard('selfcare')->login($customer);
    SelfcareSession::remember($customer, '9876543210');

    $this->get(route('selfcare.payment', $this->payment->public_id))->assertOk();

    $stranger = Account::factory()->create(['mobile' => '9000000000']);
    $theirs = OnlinePayment::factory()->create(['account_id' => $stranger->id, 'origin' => OnlinePaymentOrigin::Selfcare]);
    $this->get(route('selfcare.payment', $theirs->public_id))->assertRedirect(route('pay.result', $theirs->public_id));
});
