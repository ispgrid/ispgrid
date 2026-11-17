<?php

use App\Actions\Billing\IssueInvoice;
use App\Enums\InvoiceKind;
use App\Enums\OnlinePaymentOrigin;
use App\Enums\OnlinePaymentStatus;
use App\Http\Middleware\SelfcareSession;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\GatewaySetting;
use App\Models\OnlinePayment;
use App\Models\SelfcareAccount;
use App\Payments\PaymentGateways;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Fakes\FakeGateway;
use Tests\Fakes\OtherGateway;

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

it('sets no cookie when the gateway posts back, so the customer\'s own cookie stays', function () {
    $payment = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'origin' => OnlinePaymentOrigin::Selfcare, 'amount' => '118.00', 'dues_amount' => '118.00']);
    FakeGateway::pay($payment);
    actingAsCustomer($this->account);
    $signed = ['order' => $payment->public_id, 'signature' => hash_hmac('sha256', $payment->public_id, 'test-secret')];

    $back = $this->post(route('pay.return', 'fake'), $signed);
    $back->assertRedirect(route('selfcare.payment', $payment->public_id));
    expect($back->headers->getCookies())->toBe([]);

    // Proves only: no Set-Cookie on the return (above) and the owner's page still opens in this test session.
    $this->get(route('selfcare.payment', $payment->public_id))->assertOk()
        ->assertSee(__('Payment of :amount received. Thank you!', ['amount' => '₹118.00']));
});

it('starts nothing when quick pay is off', function () {
    AppSetting::put('billing.quick_pay', '0');
    actingAsCustomer($this->account);

    Livewire::test('pages::selfcare.dashboard')->call('pay', 'fake')
        ->assertNoRedirect()->assertSet('payError', __('The payment could not be started. Try again in a few minutes.'));
    expect(OnlinePayment::query()->count())->toBe(0);
});

it('shows one button per gateway label when two gateways are on', function () {
    app(PaymentGateways::class)->register(OtherGateway::class);
    GatewaySetting::query()->create(['gateway' => 'othergw', 'enabled' => true, 'settings' => []]);
    actingAsCustomer($this->account);

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee('Test gateway')->assertSee('Other gateway')->assertDontSee(__('Pay online'));
});

it('opens the go page for any connection on the mobile of a mobile login', function () {
    $this->account->update(['mobile' => '9876543210']);
    $this->other->update(['mobile' => '9876543210']);
    $customer = SelfcareAccount::query()->findOrFail($this->account->id);
    Auth::guard('selfcare')->login($customer);
    SelfcareSession::remember($customer, '9876543210');
    $attempt = OnlinePayment::factory()->create([
        'account_id' => $this->other->id, 'origin' => OnlinePaymentOrigin::Selfcare,
        'gateway_start' => ['kind' => 'form', 'url' => 'https://gateway.test/pay', 'fields' => ['order' => 'X'], 'orderId' => 'X'],
    ]);

    $this->get(route('selfcare.pay-go', $attempt->public_id))->assertOk();
});

it('limits gateway returns per address, without a cookie on the refusal', function () {
    foreach (range(1, 60) as $i) {
        $this->post(route('pay.return', 'fake'), ['order' => 'X', 'signature' => 'bad']);
    }

    $refused = $this->post(route('pay.return', 'fake'), ['order' => 'X', 'signature' => 'bad'])->assertStatus(429);
    expect(collect($refused->headers->getCookies())->map->getName()->all())->toBe([]);
});
