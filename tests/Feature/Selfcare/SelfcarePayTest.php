<?php

use App\Actions\Billing\IssueInvoice;
use App\Enums\InvoiceKind;
use App\Enums\OnlinePaymentOrigin;
use App\Enums\OnlinePaymentStatus;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\OnlinePayment;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Fakes\FakeGateway;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-17 10:00:00'));
    turnQuickPayOn('7');
    turnSelfcareOn();
    AppSetting::put('currency_symbol', '₹');
    $this->account = Account::factory()->selfcare()->create(['username' => 'kushal', 'expiry' => now()->addDays(20)]);
    app(IssueInvoice::class)->handle($this->account, billingLines('100'), false, InvoiceKind::Manual, now());
    $this->other = Account::factory()->selfcare()->create(['username' => 'other', 'expiry' => now()->addDays(20)]);
    app(IssueInvoice::class)->handle($this->other, billingLines('100'), false, InvoiceKind::Manual, now());
});

it('starts a payment for the logged-in customer only', function () {
    actingAsCustomer($this->account);

    Livewire::test('pages::selfcare.dashboard')->call('pay', 'fake')->assertHasNoErrors();

    $attempt = OnlinePayment::query()->sole();
    expect($attempt->account_id)->toBe($this->account->id)
        ->and($attempt->origin)->toBe(OnlinePaymentOrigin::Selfcare)
        ->and($attempt->gateway)->toBe('fake');
});

it('shows one Pay online button for one gateway', function () {
    actingAsCustomer($this->account);

    $this->get(route('selfcare.dashboard'))->assertOk()->assertSee(__('Pay online'))->assertSee("pay('fake')", false);
});

it('hands a form gateway to the go page and renders the hand-off there', function () {
    actingAsCustomer($this->account);

    $page = Livewire::test('pages::selfcare.dashboard')->call('pay', 'fake');
    $attempt = OnlinePayment::query()->sole();

    $page->assertRedirect(route('selfcare.pay-go', $attempt->public_id));
    $this->get(route('selfcare.pay-go', $attempt->public_id))->assertOk()
        ->assertSee('https://gateway.test/pay', false)->assertSee('name="order" value="'.$attempt->public_id.'"', false);
});

it('shows the error in the Renew card when there is nothing to pay', function () {
    $paid = Account::factory()->selfcare()->create(['username' => 'paid', 'expiry' => now()->addDays(60)]);
    actingAsCustomer($paid);

    Livewire::test('pages::selfcare.dashboard')->call('pay', 'fake')
        ->assertNoRedirect()->assertSet('payError', __('Nothing to pay for this username or number.'));
    expect(OnlinePayment::query()->count())->toBe(0);
});

it('shows the error when the gateway refuses to start', function () {
    actingAsCustomer($this->account);
    FakeGateway::$refuseStart = true;

    Livewire::test('pages::selfcare.dashboard')->call('pay', 'fake')
        ->assertNoRedirect()->assertSet('payError', __('The payment could not be started. Try again in a few minutes.'));
});

it('refuses a gateway that is not on', function () {
    actingAsCustomer($this->account);

    Livewire::test('pages::selfcare.dashboard')->call('pay', 'nope')->assertNoRedirect()->assertSet('payError', __('The payment could not be started. Try again in a few minutes.'));
    expect(OnlinePayment::query()->count())->toBe(0);
});

it('refuses the go page for another customer attempt', function () {
    $attempt = OnlinePayment::factory()->create([
        'account_id' => $this->other->id, 'origin' => OnlinePaymentOrigin::Selfcare, 'gateway' => 'fake',
        'gateway_start' => ['kind' => 'form', 'url' => 'https://gateway.test/pay', 'fields' => ['order' => 'X'], 'orderId' => 'X'],
    ]);
    actingAsCustomer($this->account);

    $this->get(route('selfcare.pay-go', $attempt->public_id))->assertNotFound();
});

it('refuses the go page for a quick pay attempt or one that is no longer pending', function () {
    actingAsCustomer($this->account);
    $start = ['kind' => 'form', 'url' => 'https://gateway.test/pay', 'fields' => ['order' => 'X'], 'orderId' => 'X'];
    $quick = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'gateway_start' => $start]);
    $done = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'origin' => OnlinePaymentOrigin::Selfcare, 'status' => OnlinePaymentStatus::Paid, 'gateway_start' => $start]);

    $this->get(route('selfcare.pay-go', $quick->public_id))->assertNotFound();
    $this->get(route('selfcare.pay-go', $done->public_id))->assertNotFound();
});

it('sends a visitor without a session away from the go page', function () {
    $attempt = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'origin' => OnlinePaymentOrigin::Selfcare]);

    $this->get(route('selfcare.pay-go', $attempt->public_id))->assertRedirect(route('selfcare.login'));
});

it('sets no session or CSRF cookie on a gateway return or webhook', function () {
    $payment = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'origin' => OnlinePaymentOrigin::Selfcare, 'amount' => '118.00', 'dues_amount' => '118.00']);
    FakeGateway::pay($payment);
    $signed = ['order' => $payment->public_id, 'signature' => hash_hmac('sha256', $payment->public_id, 'test-secret')];
    $forged = ['order' => $payment->public_id, 'signature' => 'forged'];

    foreach ([
        $this->post(route('pay.return', 'fake'), $signed),
        $this->post(route('pay.return', 'fake'), $forged),
        $this->post(route('pay.webhook', 'fake'), $signed),
        $this->post(route('pay.webhook', 'fake'), $forged),
    ] as $response) {
        $names = collect($response->headers->getCookies())->map->getName()->all();
        expect($names)->not->toContain(config('session.cookie'))->not->toContain('XSRF-TOKEN')->toBe([]);
    }
});

it('leaves the customer logged in after the gateway posts back', function () {
    $payment = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'origin' => OnlinePaymentOrigin::Selfcare, 'amount' => '118.00', 'dues_amount' => '118.00']);
    FakeGateway::pay($payment);
    actingAsCustomer($this->account);
    $signed = ['order' => $payment->public_id, 'signature' => hash_hmac('sha256', $payment->public_id, 'test-secret')];

    $back = $this->post(route('pay.return', 'fake'), $signed);
    $back->assertRedirect(route('selfcare.payment', $payment->public_id));
    expect($back->headers->getCookies())->toBe([]);

    // The customer's own cookie was never replaced, so the follow-up GET still sees them as the owner.
    $this->get(route('selfcare.payment', $payment->public_id))->assertOk()
        ->assertSee(__('Payment of :amount received. Thank you!', ['amount' => '₹118.00']));
});
