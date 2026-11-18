<?php

use App\Contracts\NeedsCustomerDetails;
use App\Contracts\UntestedGateway;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\GatewayUnavailable;
use App\Models\GatewaySetting;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Payments\GatewayStatus;
use App\Payments\PayPal\PayPalGateway;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const PAYPAL_ID = 'AZnotarealclientid_123';
const PAYPAL_SECRET = 'EKnotarealclientsecret_456';
const PAYPAL_WEBHOOK = '5GP028458E2496506';
const PAYPAL_ATTEMPT = '01JQUICKPAYTESTORDER000007';
const PAYPAL_ORDER = '5O190127TN364715T';
const PAYPAL_HOST = 'https://api-m.sandbox.paypal.com';

beforeEach(function () {
    Cache::flush();
    $this->payment = OnlinePayment::factory()->create([
        'public_id' => PAYPAL_ATTEMPT, 'gateway' => 'paypal', 'gateway_order_id' => PAYPAL_ORDER, 'amount' => '708.50', 'currency' => 'USD',
    ]);
});

/**
 * @param  array<string, string>  $settings
 */
function paypal(array $settings = []): PayPalGateway
{
    return new PayPalGateway([...['environment' => 'test', 'client_id' => PAYPAL_ID, 'client_secret' => PAYPAL_SECRET, 'webhook_id' => PAYPAL_WEBHOOK, 'host' => ''], ...$settings]);
}

/**
 * @return array<string, mixed>
 */
function paypalToken(string $token = 'A21AAtoken-abc'): array
{
    return ['scope' => 'https://uri.paypal.com/services/payments/payment', 'access_token' => $token, 'token_type' => 'Bearer', 'app_id' => 'APP-1', 'expires_in' => 32400, 'nonce' => '2026-11-18T00:00:00Zxyz'];
}

/**
 * @param  list<array<string, mixed>>  $captures
 * @return array<string, mixed>
 */
function paypalOrder(string $status = 'APPROVED', array $captures = [], string $value = '708.50', string $currency = 'USD'): array
{
    $unit = ['reference_id' => 'default', 'custom_id' => PAYPAL_ATTEMPT, 'amount' => ['currency_code' => $currency, 'value' => $value],
        'shipping' => ['address' => ['address_line_1' => '1 Private Street']]];

    if ($captures !== []) {
        $unit['payments'] = ['captures' => $captures];
    }

    return ['id' => PAYPAL_ORDER, 'status' => $status, 'intent' => 'CAPTURE', 'purchase_units' => [$unit],
        'payer' => ['email_address' => 'asha@example.com', 'payer_id' => 'PAYERSECRET1'], 'payment_source' => ['paypal' => ['account_id' => 'ACCTSECRET']],
        'links' => [['href' => PAYPAL_HOST.'/v2/checkout/orders/'.PAYPAL_ORDER, 'rel' => 'self', 'method' => 'GET']]];
}

/**
 * @return array<string, mixed>
 */
function paypalCapture(string $status = 'COMPLETED', string $value = '708.50', string $currency = 'USD', string $id = '3C679366HH908993F'): array
{
    return ['id' => $id, 'status' => $status, 'amount' => ['currency_code' => $currency, 'value' => $value], 'final_capture' => true,
        'create_time' => '2026-11-18T10:00:00Z', 'seller_receivable_breakdown' => ['paypal_fee' => ['value' => '9.99']]];
}

/**
 * @param  array<string, mixed>|list<array<string, mixed>>|null  $capture  the answer of the capture call
 */
function fakePaypal(mixed $order, ?array $capture = null, int $captureHttp = 200): void
{
    Http::swap(new Factory);
    $get = array_is_list($order) && $order !== [] ? collect($order)->reduce(fn ($sequence, $item) => $sequence->push($item), Http::sequence()) : Http::response($order);
    Http::fake([
        '*/v1/oauth2/token' => Http::response(paypalToken()),
        '*/v2/checkout/orders/'.PAYPAL_ORDER.'/capture' => Http::response($capture ?? paypalOrder('COMPLETED', [paypalCapture()]), $captureHttp),
        '*/v2/checkout/orders/'.PAYPAL_ORDER => $get,
    ]);
    Http::preventStrayRequests();
}

function paypalCaptureCalls(): int
{
    return Http::recorded()->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/capture'))->count();
}

function paypalTokenCalls(): int
{
    return Http::recorded()->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'oauth2/token'))->count();
}

/**
 * @return array<string, string>
 */
function paypalHeaders(?string $skip = null): array
{
    $headers = ['HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42', 'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
        'HTTP_PAYPAL_TRANSMISSION_SIG' => 'c2ln', 'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-11-18T10:00:00Z'];

    unset($headers[$skip]);

    return $headers;
}

function paypalHookBody(string $order = PAYPAL_ORDER, string $custom = PAYPAL_ATTEMPT, string $type = 'PAYMENT.CAPTURE.COMPLETED'): string
{
    $resource = str_starts_with($type, 'CHECKOUT.ORDER.')
        ? ['id' => $order, 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => $custom]]]
        : ['id' => '3C679366HH908993F', 'status' => 'COMPLETED', 'custom_id' => $custom, 'amount' => ['currency_code' => 'USD', 'value' => '1.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => $order]]];

    return (string) json_encode(['id' => 'WH-1', 'event_type' => $type, 'resource' => $resource]);
}

/**
 * @param  array<string, string>|null  $headers
 */
function paypalWebhook(string $body, ?array $headers = null): Request
{
    return Request::create('/pay/webhook/paypal', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'] + ($headers ?? paypalHeaders()), $body);
}

function fakePaypalVerify(string $status = 'SUCCESS', int $http = 200): void
{
    Http::swap(new Factory);
    Http::fake(['*/v1/oauth2/token' => Http::response(paypalToken()), '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $status], $http)]);
    Http::preventStrayRequests();
}

it('is marked untested, needs no customer details and lists the currencies', function () {
    expect(paypal())->toBeInstanceOf(UntestedGateway::class)->not->toBeInstanceOf(NeedsCustomerDetails::class)
        ->and(PayPalGateway::key())->toBe('paypal')
        ->and(PayPalGateway::label())->toBe('PayPal')
        ->and(PayPalGateway::currencies())->toContain('USD', 'EUR', 'GBP', 'JPY', 'HUF', 'TWD')->not->toContain('INR');
});

it('starts a payment with the attempt\'s amount, currency, order id and return url', function () {
    fakePaypal(paypalOrder());
    Http::fake([
        '*/v1/oauth2/token' => Http::response(paypalToken()),
        '*/v2/checkout/orders' => Http::response(['id' => PAYPAL_ORDER, 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
            ['href' => PAYPAL_HOST.'/v2/checkout/orders/'.PAYPAL_ORDER, 'rel' => 'self', 'method' => 'GET'],
            ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.PAYPAL_ORDER, 'rel' => 'payer-action', 'method' => 'GET'],
        ]], 201),
    ]);
    $return = 'https://isp.example/pay/return/paypal?attempt='.PAYPAL_ATTEMPT;

    $start = paypal()->start($this->payment, $return);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === PAYPAL_HOST.'/v1/oauth2/token'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode(PAYPAL_ID.':'.PAYPAL_SECRET))
        && $request->body() === 'grant_type=client_credentials');
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === PAYPAL_HOST.'/v2/checkout/orders'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer A21AAtoken-abc')
        && $request->hasHeader('PayPal-Request-Id', 'start-'.PAYPAL_ATTEMPT)
        && $request->data() === [
            'intent' => 'CAPTURE',
            'purchase_units' => [['custom_id' => PAYPAL_ATTEMPT, 'amount' => ['currency_code' => 'USD', 'value' => '708.50']]],
            'payment_source' => ['paypal' => ['experience_context' => ['return_url' => $return, 'cancel_url' => $return, 'user_action' => 'PAY_NOW']]],
        ]);

    expect($start->kind)->toBe('redirect')->and($start->url)->toBe('https://www.sandbox.paypal.com/checkoutnow?token='.PAYPAL_ORDER)
        ->and($start->orderId)->toBe(PAYPAL_ORDER);
});

it('falls back to the approve link and refuses an answer without a link', function () {
    Http::swap(new Factory);
    Http::fake(['*/v1/oauth2/token' => Http::response(paypalToken()), '*/v2/checkout/orders' => Http::response(['id' => PAYPAL_ORDER, 'links' => [['href' => 'https://www.sandbox.paypal.com/approve?token=x', 'rel' => 'approve']]], 201)]);
    Http::preventStrayRequests();

    expect(paypal()->start($this->payment, 'https://isp.example/r?attempt=X')->url)->toBe('https://www.sandbox.paypal.com/approve?token=x');

    Http::swap(new Factory);
    Http::fake(['*/v1/oauth2/token' => Http::response(paypalToken()), '*/v2/checkout/orders' => Http::response(['id' => PAYPAL_ORDER, 'links' => [['href' => 'http://evil.example/x', 'rel' => 'payer-action']]], 201)]);
    Http::preventStrayRequests();

    expect(fn () => paypal()->start($this->payment, 'https://isp.example/r?attempt=X'))->toThrow(GatewayUnavailable::class);

    foreach (['https://evil.example/paypal.com', 'https://paypal.com.evil.example/x', 'https://notpaypal.com/x'] as $href) {
        Http::swap(new Factory);
        Http::fake(['*/v1/oauth2/token' => Http::response(paypalToken()), '*/v2/checkout/orders' => Http::response(['id' => PAYPAL_ORDER, 'links' => [['href' => $href, 'rel' => 'payer-action']]], 201)]);
        Http::preventStrayRequests();

        expect(fn () => paypal()->start($this->payment, 'https://isp.example/r?attempt=X'))->toThrow(GatewayUnavailable::class);
    }
});

it('sends zero-decimal currencies in whole units and refuses a fraction before calling PayPal', function () {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response([])]);
    Http::preventStrayRequests();
    $fraction = OnlinePayment::factory()->create(['gateway' => 'paypal', 'amount' => '500.50', 'currency' => 'JPY']);

    expect(fn () => paypal()->start($fraction, 'https://isp.example/r?attempt=X'))->toThrow(GatewayUnavailable::class);
    Http::assertNothingSent();

    Http::swap(new Factory);
    Http::fake(['*/v1/oauth2/token' => Http::response(paypalToken()), '*/v2/checkout/orders' => Http::response(['id' => PAYPAL_ORDER, 'links' => [['href' => 'https://www.sandbox.paypal.com/p', 'rel' => 'payer-action']]], 201)]);
    Http::preventStrayRequests();
    $yen = OnlinePayment::factory()->create(['gateway' => 'paypal', 'amount' => '500.00', 'currency' => 'JPY']);

    paypal()->start($yen, 'https://isp.example/r?attempt=X');

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request->data()['purchase_units'][0]['amount'] === ['currency_code' => 'JPY', 'value' => '500']);
});

it('uses the host in the settings, and the live host for live', function () {
    Http::swap(new Factory);
    Http::fake(['*/v1/oauth2/token' => Http::response(paypalToken()), '*/v2/checkout/orders' => Http::response(['id' => PAYPAL_ORDER, 'links' => [['href' => 'https://www.paypal.com/p', 'rel' => 'payer-action']]], 201)]);
    Http::preventStrayRequests();

    paypal(['host' => 'https://paypal.example.net/'])->start($this->payment, 'https://isp.example/r?attempt=X');
    paypal(['environment' => 'live'])->start($this->payment, 'https://isp.example/r?attempt=X');

    foreach (['https://paypal.example.net/v1/oauth2/token', 'https://paypal.example.net/v2/checkout/orders', 'https://api-m.paypal.com/v1/oauth2/token', 'https://api-m.paypal.com/v2/checkout/orders'] as $url) {
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === $url);
    }
});

it('keeps the token per client and environment until a minute before it expires', function () {
    fakePaypal(paypalOrder('CREATED'));

    paypal()->status($this->payment);
    paypal()->status($this->payment);
    expect(paypalTokenCalls())->toBe(1);

    paypal(['client_id' => 'OTHER'])->status($this->payment);
    paypal(['environment' => 'live'])->status($this->payment);
    expect(paypalTokenCalls())->toBe(3);

    $this->travel(32300)->seconds();
    paypal()->status($this->payment);
    expect(paypalTokenCalls())->toBe(3);

    $this->travel(100)->seconds();
    paypal()->status($this->payment);
    expect(paypalTokenCalls())->toBe(4);
});

it('fetches a fresh token and retries once when the cached one is refused', function () {
    Cache::put('paypal.token.test.'.sha1(PAYPAL_ID.'|'.PAYPAL_HOST), 'STALE', now()->addMinutes(30));
    Http::swap(new Factory);
    Http::fake([
        '*/v1/oauth2/token' => Http::response(paypalToken('FRESH')),
        '*/v2/checkout/orders/*' => Http::sequence()->push(['name' => 'AUTHENTICATION_FAILURE'], 401)->push(paypalOrder('CREATED')),
    ]);
    Http::preventStrayRequests();

    expect(paypal()->status($this->payment)->providerStatus)->toBe('CREATED');

    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/orders/') && $request->hasHeader('Authorization', 'Bearer STALE'));
    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/orders/') && $request->hasHeader('Authorization', 'Bearer FRESH'));
    expect(paypalTokenCalls())->toBe(1);
});

it('reports paid only for the final success state with the amount and currency', function () {
    fakePaypal([paypalOrder('APPROVED'), paypalOrder('APPROVED')]);

    $result = paypal()->status($this->payment);

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET' && $request->url() === PAYPAL_HOST.'/v2/checkout/orders/'.PAYPAL_ORDER
        && $request->hasHeader('Authorization', 'Bearer A21AAtoken-abc'));
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST' && $request->url() === PAYPAL_HOST.'/v2/checkout/orders/'.PAYPAL_ORDER.'/capture'
        && $request->body() === '{}' && $request->hasHeader('PayPal-Request-Id', 'capture-'.PAYPAL_ATTEMPT) && $request->hasHeader('Prefer', 'return=representation') && $request->hasHeader('Authorization', 'Bearer A21AAtoken-abc'));

    expect($result->status)->toBe(GatewayStatus::Paid)->and($result->amount)->toBe('708.50')->and($result->currency)->toBe('USD')
        ->and($result->txnId)->toBe('3C679366HH908993F')->and($result->providerStatus)->toBe('COMPLETED')
        ->and($result->paidAt?->getTimestamp())->toBe(1794996000);
});

it('reads the capture of an already completed order without capturing', function () {
    fakePaypal(paypalOrder('COMPLETED', [paypalCapture(value: '708.50')]));

    $result = paypal()->status($this->payment);

    expect($result->status)->toBe(GatewayStatus::Paid)->and($result->txnId)->toBe('3C679366HH908993F')->and(paypalCaptureCalls())->toBe(0);
});

it('takes the amount and currency from the capture, not from the attempt', function () {
    fakePaypal(paypalOrder('COMPLETED', [paypalCapture(value: '1.00', currency: 'EUR')]));

    expect(paypal()->status($this->payment))->status->toBe(GatewayStatus::Paid)->amount->toBe('1.00')->currency->toBe('EUR');
});

it('reads a zero-decimal capture and refuses one with a fraction', function () {
    $yen = OnlinePayment::factory()->create(['gateway' => 'paypal', 'gateway_order_id' => PAYPAL_ORDER, 'amount' => '500.00', 'currency' => 'JPY']);
    fakePaypal(paypalOrder('COMPLETED', [paypalCapture(value: '500', currency: 'JPY')], '500', 'JPY'));

    expect(paypal()->status($yen))->status->toBe(GatewayStatus::Paid)->amount->toBe('500.00')->currency->toBe('JPY');

    fakePaypal(paypalOrder('COMPLETED', [paypalCapture(value: '500.50', currency: 'JPY')], '500', 'JPY'));

    expect(paypal()->status($yen)->status)->toBe(GatewayStatus::Pending);
});

it('reports failed for a final failure state and pending for anything else', function (array $order, GatewayStatus $expected) {
    fakePaypal($order);

    expect(paypal()->status($this->payment)->status)->toBe($expected);
})->with([
    'voided' => [paypalOrder('VOIDED'), GatewayStatus::Failed],
    'created' => [paypalOrder('CREATED'), GatewayStatus::Pending],
    'saved' => [paypalOrder('SAVED'), GatewayStatus::Pending],
    'payer action required' => [paypalOrder('PAYER_ACTION_REQUIRED'), GatewayStatus::Pending],
    'unknown state' => [paypalOrder('SOMETHING_NEW'), GatewayStatus::Pending],
    'completed with a pending capture' => [paypalOrder('COMPLETED', [paypalCapture('PENDING')]), GatewayStatus::Pending],
    'completed with a declined capture' => [paypalOrder('COMPLETED', [paypalCapture('DECLINED')]), GatewayStatus::Pending],
    'completed without a capture' => [paypalOrder('COMPLETED'), GatewayStatus::Pending],
]);

it('keeps a capture that is still pending as pending after capturing', function () {
    fakePaypal([paypalOrder('APPROVED'), paypalOrder('APPROVED')], paypalOrder('COMPLETED', [paypalCapture('PENDING')]));

    $result = paypal()->status($this->payment);

    expect($result->status)->toBe(GatewayStatus::Pending)->and($result->providerStatus)->toBe('PENDING');
});

it('captures an approved order once, even when asked twice', function () {
    fakePaypal([paypalOrder('APPROVED'), paypalOrder('APPROVED'), paypalOrder('COMPLETED', [paypalCapture()])]);

    $first = paypal()->status($this->payment);
    $second = paypal()->status($this->payment);

    expect($first->status)->toBe(GatewayStatus::Paid)->and($second->status)->toBe(GatewayStatus::Paid)
        ->and($second->txnId)->toBe($first->txnId)->and(paypalCaptureCalls())->toBe(1);
});

it('does not capture when the in-lock re-read shows a completed order', function () {
    fakePaypal([paypalOrder('APPROVED'), paypalOrder('COMPLETED', [paypalCapture(id: 'CAP-OTHER')])]);

    $result = paypal()->status($this->payment);

    expect($result->status)->toBe(GatewayStatus::Paid)->and($result->txnId)->toBe('CAP-OTHER')->and(paypalCaptureCalls())->toBe(0);
});

it('stays pending without capturing when the capture lock of the order is held', function () {
    Sleep::fake(syncWithCarbon: true);
    fakePaypal(paypalOrder('APPROVED'));
    $lock = Cache::lock('paypal-capture:'.PAYPAL_ORDER, 30);
    expect($lock->get())->toBeTrue();

    expect(paypal()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
    expect(paypalCaptureCalls())->toBe(0);

    $lock->release();
});

it('does not capture an approved order whose amount or currency is not the attempt\'s', function (string $value, string $currency) {
    fakePaypal(paypalOrder('APPROVED', [], $value, $currency));

    $result = paypal()->status($this->payment);

    expect($result->status)->toBe(GatewayStatus::Pending)->and($result->providerStatus)->toBe('APPROVED')->and(paypalCaptureCalls())->toBe(0);
})->with([['100.00', 'USD'], ['708.50', 'EUR'], ['0.00', 'USD']]);

it('stays pending when the capture call fails and the order is still approved, and recovers when it was completed', function () {
    fakePaypal([paypalOrder('APPROVED'), paypalOrder('APPROVED'), paypalOrder('APPROVED')], ['name' => 'INTERNAL_SERVER_ERROR'], 500);
    expect(paypal()->status($this->payment)->status)->toBe(GatewayStatus::Pending);

    fakePaypal([paypalOrder('APPROVED'), paypalOrder('APPROVED'), paypalOrder('COMPLETED', [paypalCapture()])], ['name' => 'INTERNAL_SERVER_ERROR'], 500);
    expect(paypal()->status($this->payment)->status)->toBe(GatewayStatus::Paid);
});

it('never takes an answer for another order, and never looks up without an order id', function () {
    fakePaypal([...paypalOrder('COMPLETED', [paypalCapture()]), 'id' => 'ORDER-OTHER']);
    expect(paypal()->status($this->payment)->status)->toBe(GatewayStatus::Pending);

    Http::fake();
    Http::preventStrayRequests();
    $this->payment->forceFill(['gateway_order_id' => null]);
    expect(paypal()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
    Http::assertNothingSent();
});

it('reports pending when the provider cannot be reached or answers an error', function (callable $fake) {
    $fake();

    expect(paypal()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
})->with([
    'server error' => [fn () => Http::fake(['*/v1/oauth2/token' => Http::response(paypalToken()), '*' => Http::response(['name' => 'boom'], 500)])],
    'not reachable' => [fn () => Http::fake(['*' => Http::failedConnection()])],
    'token refused' => [fn () => Http::fake(['*' => Http::response(['error' => 'invalid_client'], 401)])],
    'no capture amount' => [fn () => fakePaypal(paypalOrder('COMPLETED', [array_diff_key(paypalCapture(), ['amount' => 1])]))],
    'amount not a string' => [fn () => fakePaypal(paypalOrder('COMPLETED', [[...paypalCapture(), 'amount' => ['currency_code' => 'USD', 'value' => 708.5]]]))],
    'amount with a sign' => [fn () => fakePaypal(paypalOrder('COMPLETED', [paypalCapture(value: '-5.00')]))],
]);

it('gives up after a second refusal and does not leak the secret', function () {
    Http::swap(new Factory);
    Http::fake(['*/v1/oauth2/token' => Http::response(paypalToken()), '*/v2/checkout/orders*' => Http::response(['secret' => PAYPAL_SECRET], 401)]);
    Http::preventStrayRequests();

    expect(paypal()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
    expect(paypalTokenCalls())->toBe(2);

    Cache::flush();
    try {
        paypal()->start($this->payment, 'https://isp.example/r?attempt=X');
        $this->fail('Expected GatewayUnavailable');
    } catch (GatewayUnavailable $exception) {
        expect($exception->getMessage())->not->toContain(PAYPAL_SECRET)->and($exception->httpStatus)->toBe(401);
    }
});

it('accepts a genuine return and webhook and names the attempt', function () {
    expect(paypal()->verify(Request::create('/pay/return/paypal', 'GET', ['attempt' => PAYPAL_ATTEMPT])))->toBe(PAYPAL_ATTEMPT)
        ->and(paypal()->verify(Request::create('/pay/return/paypal', 'GET', ['attempt' => PAYPAL_ATTEMPT, 'token' => PAYPAL_ORDER, 'PayerID' => 'X'])))->toBe(PAYPAL_ATTEMPT);

    fakePaypalVerify();
    $capture = paypalHookBody();
    $approved = paypalHookBody(type: 'CHECKOUT.ORDER.APPROVED');

    expect(paypal()->verify(paypalWebhook($capture)))->toBe(PAYPAL_ATTEMPT)
        ->and(paypal()->verify(paypalWebhook($approved)))->toBe(PAYPAL_ATTEMPT);

    $spaced = '{ "event_type" : "PAYMENT.CAPTURE.COMPLETED",'.'
'.'  "resource":{"custom_id":"'.PAYPAL_ATTEMPT.'","amount":{"value":"1.0"},"supplementary_data":{"related_ids":{"order_id":"'.PAYPAL_ORDER.'"}}} }';
    expect(paypal()->verify(paypalWebhook($spaced)))->toBe(PAYPAL_ATTEMPT);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/verify-webhook-signature')
        && str_contains($request->body(), '"webhook_event":'.$spaced.'}')
        && str_contains($request->header('Content-Type')[0], 'application/json')
        && json_decode($request->body(), true)['webhook_id'] === PAYPAL_WEBHOOK);

    foreach ([$capture, $approved] as $body) {
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === PAYPAL_HOST.'/v1/notifications/verify-webhook-signature'
            && $request->hasHeader('Authorization', 'Bearer A21AAtoken-abc')
            && $request->data() === [
                'webhook_id' => PAYPAL_WEBHOOK, 'auth_algo' => 'SHA256withRSA',
                'cert_url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42', 'transmission_id' => 'tx-1', 'transmission_sig' => 'c2ln',
                'transmission_time' => '2026-11-18T10:00:00Z', 'webhook_event' => json_decode($body, true),
            ]);
    }
});

it('does not ask PayPal about an event for an order we never started', function () {
    Http::fake();

    expect(paypal()->verify(paypalWebhook(paypalHookBody('ORDER-UNKNOWN'))))->toBeNull()
        ->and(paypal()->verify(paypalWebhook(paypalHookBody(custom: 'NOSUCHATTEMPT'))))->toBeNull();

    Http::assertNothingSent();
});

it('rejects a bad signature, an unknown attempt and another gateway\'s attempt', function () {
    $other = OnlinePayment::factory()->create(['public_id' => 'OTHERGATEWAYATTEMPT00001', 'gateway' => 'fake', 'gateway_order_id' => 'ORDER-FAKE']);
    $return = fn (array $query): Request => Request::create('/pay/return/paypal', 'GET', $query);

    expect(paypal()->verify($return(['attempt' => 'NOSUCHATTEMPT'])))->toBeNull()
        ->and(paypal()->verify($return(['attempt' => $other->public_id])))->toBeNull()
        ->and(paypal()->verify($return([])))->toBeNull()
        ->and(paypal()->verify($return(['attempt' => ['x']])))->toBeNull()
        ->and(paypal()->verify($return(['token' => PAYPAL_ORDER])))->toBeNull()
        ->and(paypal()->verify($return(['attempt' => PAYPAL_ATTEMPT, 'token' => 'ORDER-OTHER'])))->toBeNull()
        ->and(paypal()->verify($return(['attempt' => PAYPAL_ATTEMPT, 'token' => ['x']])))->toBeNull();

    $body = paypalHookBody();
    fakePaypalVerify('FAILURE');
    expect(paypal()->verify(paypalWebhook($body)))->toBeNull();
    fakePaypalVerify('');
    expect(paypal()->verify(paypalWebhook($body)))->toBeNull();
    fakePaypalVerify('SUCCESS', 500);
    expect(paypal()->verify(paypalWebhook($body)))->toBeNull();

    fakePaypalVerify();
    foreach (array_keys(paypalHeaders()) as $missing) {
        expect(paypal()->verify(paypalWebhook($body, paypalHeaders($missing))))->toBeNull();
    }
    expect(paypal()->verify(paypalWebhook($body, [...paypalHeaders(), 'HTTP_PAYPAL_TRANSMISSION_SIG' => ''])))->toBeNull()
        ->and(paypal(['webhook_id' => ''])->verify(paypalWebhook($body)))->toBeNull()
        ->and(paypal()->verify(paypalWebhook('')))->toBeNull()
        ->and(paypal()->verify(paypalWebhook('{}')))->toBeNull();
    Http::assertNothingSent();

    fakePaypalVerify();
    $unknown = paypalHookBody(custom: 'NOSUCHATTEMPT');
    $foreign = paypalHookBody('ORDER-FAKE', 'OTHERGATEWAYATTEMPT00001');
    $mismatch = paypalHookBody('ORDER-OTHER');
    $noIds = (string) json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAP1']]);
    $customOnly = (string) json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAP1', 'custom_id' => PAYPAL_ATTEMPT]]);

    expect(paypal()->verify(paypalWebhook($unknown)))->toBeNull()
        ->and(paypal()->verify(paypalWebhook($foreign)))->toBeNull()
        ->and(paypal()->verify(paypalWebhook($mismatch)))->toBeNull()
        ->and(paypal()->verify(paypalWebhook($noIds)))->toBeNull()
        ->and(paypal()->verify(paypalWebhook($customOnly)))->toBeNull();
});

describe('through the routes', function () {
    beforeEach(function () {
        GatewaySetting::query()->updateOrCreate(['gateway' => 'paypal'], ['enabled' => true, 'settings' => ['environment' => 'test', 'client_id' => PAYPAL_ID, 'client_secret' => PAYPAL_SECRET, 'webhook_id' => PAYPAL_WEBHOOK, 'host' => '']]);
    });

    it('records nothing when a genuine-looking success return meets a status that is not paid', function (array $order, OnlinePaymentStatus $expected) {
        fakePaypal($order);
        Http::fake([
            '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            '*/v1/oauth2/token' => Http::response(paypalToken()),
            '*/v2/checkout/orders/'.PAYPAL_ORDER => Http::response($order),
        ]);
        $body = paypalHookBody();

        $this->get(route('pay.return', ['paypal', 'attempt' => PAYPAL_ATTEMPT, 'token' => PAYPAL_ORDER, 'PayerID' => 'X']));
        $this->call('POST', route('pay.webhook', 'paypal'), [], [], [], ['CONTENT_TYPE' => 'application/json'] + paypalHeaders(), $body)->assertOk();

        expect(Payment::query()->count())->toBe(0)->and($this->payment->fresh()->status)->toBe($expected);
    })->with([
        'created' => [paypalOrder('CREATED'), OnlinePaymentStatus::Pending],
        'voided' => [paypalOrder('VOIDED'), OnlinePaymentStatus::Failed],
        'approved for a lower amount' => [paypalOrder('APPROVED', [], '1.00'), OnlinePaymentStatus::Pending],
    ]);

    it('marks the attempt for attention when PayPal captured a lower amount or another currency', function (array $capture) {
        fakePaypal([paypalOrder('APPROVED'), paypalOrder('APPROVED')], paypalOrder('COMPLETED', [$capture]));

        $this->get(route('pay.return', ['paypal', 'attempt' => PAYPAL_ATTEMPT, 'token' => PAYPAL_ORDER]));

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::NeedsAttention)->and(Payment::query()->count())->toBe(0);
    })->with([
        'lower amount' => [paypalCapture(value: '1.00')],
        'other currency' => [paypalCapture(currency: 'EUR')],
    ]);

    it('ignores a webhook PayPal does not confirm, and a return for another order', function () {
        fakePaypalVerify('FAILURE');

        $this->call('POST', route('pay.webhook', 'paypal'), [], [], [], ['CONTENT_TYPE' => 'application/json'] + paypalHeaders(), paypalHookBody())->assertNotFound();
        $this->get(route('pay.return', ['paypal', 'attempt' => PAYPAL_ATTEMPT, 'token' => 'ORDER-OTHER']));

        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/v2/checkout/orders'));
        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending)->and(Payment::query()->count())->toBe(0);
    });

    it('keeps keys, tokens and signatures out of the stored answer', function () {
        fakePaypal(paypalOrder('CREATED'));

        $this->get(route('pay.return', ['paypal', 'attempt' => PAYPAL_ATTEMPT, 'token' => PAYPAL_ORDER]));

        $stored = (string) json_encode($this->payment->fresh()->gateway_response);
        expect($stored)->toContain(PAYPAL_ORDER)
            ->not->toContain('asha@example.com')->not->toContain('PAYERSECRET1')->not->toContain('ACCTSECRET')->not->toContain('1 Private Street')
            ->not->toContain('A21AAtoken-abc')->not->toContain(PAYPAL_SECRET)->not->toContain(PAYPAL_WEBHOOK);
    });
});
