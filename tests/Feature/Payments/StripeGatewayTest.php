<?php

use App\Contracts\NeedsCustomerDetails;
use App\Contracts\UntestedGateway;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\GatewayUnavailable;
use App\Models\GatewaySetting;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Payments\GatewayStatus;
use App\Payments\Stripe\StripeGateway;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const STRIPE_KEY = 'sk_test_notarealkey_51Abc';
const STRIPE_HOOK_SECRET = 'whsec_notarealsecret_9f3';
const STRIPE_ATTEMPT = '01JQUICKPAYTESTORDER000006';
const STRIPE_SESSION = 'cs_test_a1B2c3D4';
const STRIPE_HOST = 'https://api.stripe.com';

beforeEach(function () {
    $this->payment = OnlinePayment::factory()->create([
        'public_id' => STRIPE_ATTEMPT, 'gateway' => 'stripe', 'gateway_order_id' => STRIPE_SESSION, 'amount' => '708.50', 'currency' => 'USD',
    ]);
});

/**
 * @param  array<string, string>  $settings
 */
function stripe(array $settings = []): StripeGateway
{
    return new StripeGateway([...['environment' => 'test', 'secret_key' => STRIPE_KEY, 'webhook_secret' => STRIPE_HOOK_SECRET, 'host' => ''], ...$settings]);
}

/**
 * @return array<string, mixed>
 */
function stripeSession(string $payment = 'paid', int|float|string $total = 70850, string $currency = 'usd', string $status = 'complete'): array
{
    return ['id' => STRIPE_SESSION, 'object' => 'checkout.session', 'status' => $status, 'payment_status' => $payment, 'amount_total' => $total,
        'currency' => $currency, 'payment_intent' => 'pi_3Abc', 'client_reference_id' => STRIPE_ATTEMPT, 'client_secret' => 'cs_SECRETTOKEN',
        'customer_details' => ['email' => 'asha@example.com'], 'url' => 'https://checkout.stripe.com/c/pay/cs_test_a1B2c3D4#SECRETFRAGMENT'];
}

/**
 * @param  array<string, mixed>  $session
 */
function fakeStripe(array $session, int $http = 200): void
{
    Http::swap(new Factory);
    Http::fake(['*/v1/checkout/sessions*' => Http::response($session, $http)]);
    Http::preventStrayRequests();
}

/**
 * @return array<string, mixed>
 */
function stripeForm(ClientRequest $request): array
{
    parse_str($request->body(), $fields);

    return $fields;
}

function stripeHookBody(string $session = STRIPE_SESSION, string $reference = STRIPE_ATTEMPT): string
{
    return (string) json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $session, 'client_reference_id' => $reference, 'payment_status' => 'paid', 'amount_total' => 1]]]);
}

function stripeSignature(string $body, ?int $time = null, string $secret = STRIPE_HOOK_SECRET): string
{
    $time ??= now()->getTimestamp();

    return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret);
}

function stripeWebhook(string $body, ?string $header): Request
{
    return Request::create('/pay/webhook/stripe', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'] + ($header === null ? [] : ['HTTP_STRIPE_SIGNATURE' => $header]), $body);
}

it('is marked untested, needs no customer details and lists the currencies', function () {
    expect(stripe())->toBeInstanceOf(UntestedGateway::class)->not->toBeInstanceOf(NeedsCustomerDetails::class)
        ->and(StripeGateway::key())->toBe('stripe')
        ->and(StripeGateway::currencies())->toContain('USD', 'EUR', 'GBP', 'INR', 'JPY')
        ->not->toContain('KWD', 'BHD', 'JOD', 'OMR', 'TND');
});

it('starts a payment with the attempt\'s amount, currency, order id and return url', function () {
    fakeStripe(['id' => STRIPE_SESSION, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_a1B2c3D4']);
    $return = 'https://isp.example/pay/return/stripe?attempt='.STRIPE_ATTEMPT;

    $start = stripe()->start($this->payment, $return);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === STRIPE_HOST.'/v1/checkout/sessions'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer '.STRIPE_KEY)
        && str_contains($request->header('Content-Type')[0], 'application/x-www-form-urlencoded')
        && stripeForm($request) === ['mode' => 'payment', 'line_items' => [['price_data' => ['currency' => 'usd', 'unit_amount' => '70850', 'product_data' => ['name' => 'Internet bill']], 'quantity' => '1']],
            'client_reference_id' => STRIPE_ATTEMPT, 'success_url' => $return.'&session_id={CHECKOUT_SESSION_ID}', 'cancel_url' => $return]
        && str_contains($request->body(), 'success_url='.urlencode($return.'&session_id={CHECKOUT_SESSION_ID}'))
    );

    expect($start->kind)->toBe('redirect')->and($start->url)->toBe('https://checkout.stripe.com/c/pay/cs_test_a1B2c3D4')
        ->and($start->orderId)->toBe(STRIPE_SESSION);
});

it('charges a zero-decimal currency in whole units', function () {
    fakeStripe(['id' => STRIPE_SESSION, 'url' => 'https://checkout.stripe.com/c/pay/x']);
    $yen = OnlinePayment::factory()->create(['gateway' => 'stripe', 'amount' => '500.00', 'currency' => 'JPY']);

    stripe()->start($yen, 'https://isp.example/r?attempt=X');
    Http::assertSent(fn (ClientRequest $request): bool => stripeForm($request)['line_items'][0]['price_data']['unit_amount'] === '500' && stripeForm($request)['line_items'][0]['price_data']['currency'] === 'jpy');

    fakeStripe(stripeSession('paid', 500, 'jpy'));
    $result = stripe()->status($yen->forceFill(['gateway_order_id' => STRIPE_SESSION]));
    expect($result->amount)->toBe('500.00')->and($result->currency)->toBe('JPY');
});

it('uses the host in the settings and refuses a start without a url', function () {
    fakeStripe(['id' => STRIPE_SESSION, 'url' => 'https://checkout.stripe.com/c/pay/x']);
    stripe(['host' => 'https://stripe.example.net/'])->start($this->payment, 'https://isp.example/r?attempt=X');
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://stripe.example.net/v1/checkout/sessions');

    Http::swap(new Factory);
    Http::fake(['*' => Http::response(['error' => ['message' => 'bad '.STRIPE_KEY]], 400)]);
    Http::preventStrayRequests();
    try {
        stripe()->start($this->payment, 'https://isp.example/r?attempt=X');
        $this->fail('Expected GatewayUnavailable');
    } catch (GatewayUnavailable $exception) {
        expect($exception->getMessage())->not->toContain(STRIPE_KEY)->and($exception->httpStatus)->toBe(400);
    }
});

it('refuses a start without a checkout url', function () {
    fakeStripe(['id' => STRIPE_SESSION]);

    expect(fn () => stripe()->start($this->payment, 'https://isp.example/r?attempt=X'))->toThrow(GatewayUnavailable::class);
});

it('reports paid only for the final success state with the amount and currency', function () {
    fakeStripe(stripeSession());

    $result = stripe()->status($this->payment);

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
        && $request->url() === STRIPE_HOST.'/v1/checkout/sessions/'.STRIPE_SESSION
        && $request->hasHeader('Authorization', 'Bearer '.STRIPE_KEY));

    expect($result->status)->toBe(GatewayStatus::Paid)->and($result->amount)->toBe('708.50')->and($result->currency)->toBe('USD')
        ->and($result->txnId)->toBe('pi_3Abc')->and($result->providerStatus)->toBe('paid');
});

it('takes the amount and currency from the answer, not from the attempt', function () {
    fakeStripe(stripeSession('paid', 100, 'eur'));

    expect(stripe()->status($this->payment))->status->toBe(GatewayStatus::Paid)->amount->toBe('1.00')->currency->toBe('EUR');
});

it('reports failed for a final failure state and pending for anything else', function (array $session, GatewayStatus $expected) {
    fakeStripe($session);

    expect(stripe()->status($this->payment)->status)->toBe($expected);
})->with([
    'expired' => [stripeSession('unpaid', 70850, 'usd', 'expired'), GatewayStatus::Failed],
    'open' => [stripeSession('unpaid', 70850, 'usd', 'open'), GatewayStatus::Pending],
    'complete but unpaid (async)' => [stripeSession('unpaid'), GatewayStatus::Pending],
    'no payment required' => [stripeSession('no_payment_required'), GatewayStatus::Pending],
    'unknown state' => [stripeSession('something_new', 70850, 'usd', 'odd'), GatewayStatus::Pending],
]);

it('never takes a paid answer for another session as paid, and never looks up without a session id', function () {
    fakeStripe([...stripeSession(), 'id' => 'cs_test_other']);
    expect(stripe()->status($this->payment)->status)->toBe(GatewayStatus::Pending);

    Http::fake();
    Http::preventStrayRequests();
    $this->payment->forceFill(['gateway_order_id' => null]);
    expect(stripe()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
    Http::assertNothingSent();
});

it('reports pending when the provider cannot be reached or answers an error', function (callable $fake) {
    $fake();

    expect(stripe()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
})->with([
    'server error' => [fn () => fakeStripe(['error' => 'boom'], 500)],
    'refused' => [fn () => fakeStripe(['error' => 'auth'], 401)],
    'not reachable' => [fn () => Http::fake(['*' => Http::failedConnection()])],
    'no amount' => [fn () => fakeStripe(array_diff_key(stripeSession(), ['amount_total' => 1]))],
    'no currency' => [fn () => fakeStripe(array_diff_key(stripeSession(), ['currency' => 1]))],
    'amount not whole' => [fn () => fakeStripe(stripeSession('paid', 70850.5))],
    'amount a string' => [fn () => fakeStripe(stripeSession('paid', 'lots'))],
]);

it('accepts a genuine return and webhook and names the attempt', function () {
    $body = stripeHookBody();
    $good = explode('v1=', stripeSignature($body))[1];

    expect(stripe()->verify(Request::create('/pay/return/stripe', 'GET', ['attempt' => STRIPE_ATTEMPT])))->toBe(STRIPE_ATTEMPT)
        ->and(stripe()->verify(Request::create('/pay/return/stripe', 'GET', ['attempt' => STRIPE_ATTEMPT, 'session_id' => STRIPE_SESSION])))->toBe(STRIPE_ATTEMPT)
        ->and(stripe()->verify(stripeWebhook($body, stripeSignature($body))))->toBe(STRIPE_ATTEMPT)
        ->and(stripe()->verify(stripeWebhook($body, 't='.now()->getTimestamp().',v1=bad,v1='.$good.',v0=zzz,payload=abc')))->toBe(STRIPE_ATTEMPT);
});

it('rejects a bad signature, an unknown attempt and another gateway\'s attempt', function () {
    $other = OnlinePayment::factory()->create(['public_id' => 'OTHERGATEWAYATTEMPT00001', 'gateway' => 'fake', 'gateway_order_id' => 'cs_test_fake']);
    $return = fn (array $query): Request => Request::create('/pay/return/stripe', 'GET', $query);
    $body = stripeHookBody();
    $now = now()->getTimestamp();

    expect(stripe()->verify($return(['attempt' => 'NOSUCHATTEMPT'])))->toBeNull()
        ->and(stripe()->verify($return(['attempt' => $other->public_id])))->toBeNull()
        ->and(stripe()->verify($return([])))->toBeNull()
        ->and(stripe()->verify($return(['attempt' => ['x']])))->toBeNull()
        ->and(stripe()->verify($return(['session_id' => STRIPE_SESSION])))->toBeNull()
        ->and(stripe()->verify($return(['attempt' => STRIPE_ATTEMPT, 'session_id' => 'cs_test_other'])))->toBeNull()
        ->and(stripe()->verify(stripeWebhook($body, null)))->toBeNull()
        ->and(stripe()->verify(stripeWebhook($body, '')))->toBeNull()
        ->and(stripe()->verify(stripeWebhook($body, stripeSignature($body, secret: 'whsec_wrong'))))->toBeNull()
        ->and(stripe()->verify(stripeWebhook($body, 'v1='.hash_hmac('sha256', $now.'.'.$body, STRIPE_HOOK_SECRET))))->toBeNull()
        ->and(stripe()->verify(stripeWebhook($body, 't='.$now)))->toBeNull()
        ->and(stripe()->verify(stripeWebhook($body, 't='.$now.',v0='.hash_hmac('sha256', $now.'.'.$body, STRIPE_HOOK_SECRET))))->toBeNull()
        ->and(stripe()->verify(stripeWebhook($body, stripeSignature($body, $now - 301))))->toBeNull()
        ->and(stripe()->verify(stripeWebhook($body, stripeSignature($body, $now + 301))))->toBeNull()
        ->and(stripe()->verify(stripeWebhook(str_replace('"amount_total":1', '"amount_total":2', $body), stripeSignature($body))))->toBeNull()
        ->and(stripe(['webhook_secret' => ''])->verify(stripeWebhook($body, stripeSignature($body, secret: ''))))->toBeNull();

    $unknown = stripeHookBody(reference: 'NOSUCHATTEMPT');
    $foreign = stripeHookBody('cs_test_fake', 'OTHERGATEWAYATTEMPT00001');
    $mismatch = stripeHookBody('cs_test_other');

    expect(stripe()->verify(stripeWebhook($unknown, stripeSignature($unknown))))->toBeNull()
        ->and(stripe()->verify(stripeWebhook($foreign, stripeSignature($foreign))))->toBeNull()
        ->and(stripe()->verify(stripeWebhook($mismatch, stripeSignature($mismatch))))->toBeNull();
});

describe('through the routes', function () {
    beforeEach(function () {
        GatewaySetting::query()->updateOrCreate(['gateway' => 'stripe'], ['enabled' => true, 'settings' => ['environment' => 'test', 'secret_key' => STRIPE_KEY, 'webhook_secret' => STRIPE_HOOK_SECRET, 'host' => '']]);
    });

    it('records nothing when a genuine-looking success return meets a status that is not paid', function (array $session, OnlinePaymentStatus $expected) {
        fakeStripe($session);
        $body = stripeHookBody();

        $this->get(route('pay.return', ['stripe', 'attempt' => STRIPE_ATTEMPT, 'session_id' => STRIPE_SESSION, 'payment_status' => 'paid']));
        $this->call('POST', route('pay.webhook', 'stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => stripeSignature($body)], $body)->assertOk();

        expect(Payment::query()->count())->toBe(0)->and($this->payment->fresh()->status)->toBe($expected);
    })->with([
        'open' => [stripeSession('unpaid', 70850, 'usd', 'open'), OnlinePaymentStatus::Pending],
        'expired' => [stripeSession('unpaid', 70850, 'usd', 'expired'), OnlinePaymentStatus::Failed],
    ]);

    it('marks the attempt for attention when Stripe took a lower amount or another currency', function (array $session) {
        fakeStripe($session);

        $this->get(route('pay.return', ['stripe', 'attempt' => STRIPE_ATTEMPT]));

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::NeedsAttention)->and(Payment::query()->count())->toBe(0);
    })->with([
        'lower amount' => [stripeSession('paid', 100)],
        'other currency' => [stripeSession('paid', 70850, 'eur')],
    ]);

    it('ignores a webhook with a wrong signature', function () {
        fakeStripe(stripeSession());
        $body = stripeHookBody();

        $this->call('POST', route('pay.webhook', 'stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => stripeSignature($body, secret: 'whsec_nope')], $body)->assertNotFound();

        Http::assertNothingSent();
        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending);
    });

    it('keeps keys, tokens and signatures out of the stored answer', function () {
        fakeStripe(stripeSession('unpaid', 70850, 'usd', 'open'));

        $this->get(route('pay.return', ['stripe', 'attempt' => STRIPE_ATTEMPT]));

        $stored = (string) json_encode($this->payment->fresh()->gateway_response);
        expect($stored)->toContain(STRIPE_SESSION)
            ->not->toContain('cs_SECRETTOKEN')->not->toContain('SECRETFRAGMENT')->not->toContain(STRIPE_KEY)->not->toContain(STRIPE_HOOK_SECRET)
            ->not->toContain('asha@example.com');
    });
});
