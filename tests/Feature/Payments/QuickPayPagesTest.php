<?php

use App\Actions\Billing\IssueInvoice;
use App\Enums\InvoiceKind;
use App\Enums\OnlinePaymentStatus;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\GatewaySetting;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Models\Plan;
use App\Payments\PayCode;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeGateway;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    turnQuickPayOn('7');
    AppSetting::put('currency_symbol', '₹');
    $plan = Plan::factory()->create(['name' => 'Gold 100', 'price' => 500, 'validity' => 30]);
    $this->account = Account::factory()->create(['username' => 'kushal', 'fullname' => 'Kushal Example', 'mobile' => '9876543210', 'plan_id' => $plan->id, 'expiry' => now()->addDays(20)]);
    app(IssueInvoice::class)->handle($this->account, billingLines('100'), false, InvoiceKind::Manual, now());
});

/**
 * The Pay button's hidden token from a search result page.
 */
function payToken(string $html): string
{
    preg_match('/name="token" value="([^"]+)"/', $html, $match);

    return html_entity_decode($match[1] ?? '');
}

function signedReturn(OnlinePayment $payment): array
{
    return ['order' => $payment->public_id, 'signature' => hash_hmac('sha256', $payment->public_id, 'test-secret')];
}

it('is closed while quick pay, invoicing or every gateway is off', function (Closure $off) {
    $off();

    $this->get(route('pay.show'))->assertNotFound();
    $this->post(route('pay.find'), ['who' => 'kushal'])->assertNotFound();
    $this->get(route('pay.code', PayCode::for($this->account->id)))->assertNotFound();
})->with([
    'quick pay off' => fn () => AppSetting::put('billing.quick_pay', '0'),
    'invoicing off' => fn () => AppSetting::put('billing.enabled', '0'),
    'gateway off' => fn () => GatewaySetting::query()->update(['enabled' => false]),
]);

it('shows the search form with the ISP name and needs no login', function () {
    $this->get(route('pay.show'))
        ->assertOk()
        ->assertSee('Example ISP')
        ->assertSee(__('Username or mobile number'));
});

it('shows only a masked username and the amount', function () {
    $this->post(route('pay.find'), ['who' => '98765 43210'])
        ->assertOk()
        ->assertSee('ku***al')
        ->assertSee('₹118.00')
        ->assertSee(__('Pay :amount', ['amount' => '₹118.00']))
        ->assertDontSee('Kushal Example')
        ->assertDontSee('kushal<')
        ->assertDontSee('Gold 100')
        ->assertDontSee('9876543210');
});

it('opens the pay link from a reminder with the customer already found', function () {
    $this->get(route('pay.code', PayCode::for($this->account->id)))
        ->assertOk()
        ->assertSee('ku***al')
        ->assertSee(__('Pay :amount', ['amount' => '₹118.00']))
        ->assertDontSee('Kushal Example')
        ->assertDontSee('9876543210');
});

it('pays from the pay link like from a search', function () {
    $token = payToken($this->get(route('pay.code', PayCode::for($this->account->id)))->getContent());

    $this->post(route('pay.start'), ['token' => $token, 'gateway' => 'fake'])->assertOk();

    expect(OnlinePayment::query()->sole()->account_id)->toBe($this->account->id);
});

it('sends a wrong pay link to the search', function (Closure $code) {
    $this->get('/p/'.$code($this->account->id))
        ->assertRedirect(route('pay.show'))
        ->assertSessionHas('pay_message', __('That link is not valid. Search by username or mobile number.'));
})->with([
    'changed check' => fn (int $id): string => $id.'-aaaaaa',
    'other customer' => fn (int $id): string => ($id + 1).substr(PayCode::for($id), strlen((string) $id)),
    'no check' => fn (int $id): string => (string) $id,
    'not a code' => fn (int $id): string => 'hello',
]);

it('says nothing is due on the pay link of a paid-up or deleted customer', function (string $state) {
    $other = Account::factory()->create(['username' => 'paidup']);

    if ($state === 'deleted') {
        $other->delete();
    }

    $this->get(route('pay.code', PayCode::for($other->id)))
        ->assertOk()
        ->assertSee(__('Nothing to pay for this username or number.'));
})->with(['paid up', 'deleted']);

it('answers an unknown name exactly as a customer with nothing to pay', function () {
    Account::factory()->create(['username' => 'paidup']);

    $unknown = $this->post(route('pay.find'), ['who' => 'nobody'])->assertOk();
    $paidUp = $this->post(route('pay.find'), ['who' => 'paidup'])->assertOk();
    $strip = fn (string $html): string => (string) preg_replace(['/name="_token" value="[^"]+"/', '/value="(nobody|paidup)"/', '/<meta name="csrf-token"[^>]+>/'], '', $html);

    expect($strip($unknown->getContent()))->toBe($strip($paidUp->getContent()))
        ->and($unknown->getContent())->toContain(__('Nothing to pay for this username or number.'));
});

it('limits searches from one address', function () {
    foreach (range(1, 10) as $try) {
        $this->post(route('pay.find'), ['who' => 'nobody'])->assertOk();
    }

    $this->post(route('pay.find'), ['who' => 'nobody'])->assertTooManyRequests();
});

it('sends the customer to the gateway with the amount worked out again', function () {
    $token = payToken($this->post(route('pay.find'), ['who' => 'kushal'])->getContent());

    $this->post(route('pay.start'), ['token' => $token, 'gateway' => 'fake'])
        ->assertOk()
        ->assertSee('action="https://gateway.test/pay"', false)
        ->assertSee('name="amount" value="118.00"', false);

    expect(OnlinePayment::query()->sole())->account_id->toBe($this->account->id)->amount->toBe('118.00');
});

it('refuses a changed or stale Pay button', function () {
    $token = payToken($this->post(route('pay.find'), ['who' => 'kushal'])->getContent());

    $this->post(route('pay.start'), ['token' => $token.'x', 'gateway' => 'fake'])
        ->assertRedirect(route('pay.show'))
        ->assertSessionHas('pay_message', __('That page was open too long. Search again.'));

    $this->travel(16)->minutes();
    $this->post(route('pay.start'), ['token' => $token, 'gateway' => 'fake'])->assertRedirect(route('pay.show'));
    $this->post(route('pay.start'), ['token' => $token, 'gateway' => 'other'])->assertSessionHasErrors('gateway');

    expect(OnlinePayment::query()->count())->toBe(0);
});

it('settles on the return and shows the receipt', function () {
    $token = payToken($this->post(route('pay.find'), ['who' => 'kushal'])->getContent());
    $this->post(route('pay.start'), ['token' => $token, 'gateway' => 'fake']);
    $payment = OnlinePayment::query()->sole();
    FakeGateway::pay($payment);

    $this->post(route('pay.return', 'fake'), signedReturn($payment))->assertRedirect(route('pay.result', $payment->public_id));

    $this->get(route('pay.result', $payment->public_id))
        ->assertOk()
        ->assertSee(__('Payment of :amount received. Thank you!', ['amount' => '₹118.00']))
        ->assertSee('RCPT-'.Payment::query()->sole()->id)
        ->assertDontSee('Kushal Example');
});

it('shows a pending or failed payment as such', function () {
    $payment = OnlinePayment::factory()->create(['account_id' => $this->account->id]);
    $this->get(route('pay.result', $payment->public_id))->assertSee(__('Check again'));

    $payment->update(['status' => OnlinePaymentStatus::Failed]);
    $this->get(route('pay.result', $payment->public_id))->assertSee(__('Try again'));

    $this->get('/pay/result/NOTAREALID0000000000000000')->assertNotFound();
});

it('settles nothing on a return or webhook the gateway did not sign', function () {
    $payment = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'amount' => '118.00', 'dues_amount' => '118.00']);
    FakeGateway::pay($payment);
    $forged = ['order' => $payment->public_id, 'signature' => 'forged'];

    $this->post(route('pay.return', 'fake'), $forged)->assertRedirect(route('pay.show'));
    $this->post(route('pay.webhook', 'fake'), $forged)->assertNotFound();
    $this->post(route('pay.webhook', 'nope'), signedReturn($payment))->assertNotFound();

    expect($payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending)
        ->and(FakeGateway::$asked)->toBe([]);
});

it('settles from the webhook, even with quick pay turned off since', function () {
    $payment = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'amount' => '118.00', 'dues_amount' => '118.00']);
    FakeGateway::pay($payment);
    AppSetting::put('billing.quick_pay', '0');

    $this->post(route('pay.webhook', 'fake'), signedReturn($payment))->assertOk()->assertSee('OK');
    $this->post(route('pay.webhook', 'fake'), signedReturn($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(OnlinePaymentStatus::Paid)
        ->and(Payment::query()->count())->toBe(1);
});

it('speaks the panel language', function () {
    AppSetting::put('locale', 'hi');

    $this->get(route('pay.show'))->assertSee('अपने बिल का भुगतान करें');
});
