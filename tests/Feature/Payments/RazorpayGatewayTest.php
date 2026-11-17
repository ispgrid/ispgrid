<?php

use App\Contracts\NeedsCustomerDetails;
use App\Contracts\UntestedGateway;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\GatewayUnavailable;
use App\Models\Account;
use App\Models\GatewaySetting;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Payments\GatewayStatus;
use App\Payments\Razorpay\RazorpayGateway;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const RAZORPAY_KEY_ID = 'rzp_test_KEYIDFORTESTS01';
const RAZORPAY_SECRET = 'key-secret-value-5c2e';
const RAZORPAY_HOOK_SECRET = 'hook-secret-value-81ab';
const RAZORPAY_ATTEMPT = '01JQUICKPAYTESTORDER000002';
const RAZORPAY_ORDER = 'order_TESTORDER000001';
const RAZORPAY_API = 'https://api.razorpay.com';

beforeEach(function () {
    $this->payment = OnlinePayment::factory()->create([
        'public_id' => RAZORPAY_ATTEMPT, 'gateway' => 'razorpay', 'gateway_order_id' => RAZORPAY_ORDER, 'amount' => '708.50', 'currency' => 'INR',
        'account_id' => Account::factory()->create(['fullname' => 'Asha Rao', 'mobile' => '9876543210', 'email' => 'asha@example.com']),
    ]);
});

/**
 * @param  array<string, string>  $settings
 */
function razorpay(array $settings = []): RazorpayGateway
{
    return new RazorpayGateway(razorpaySettings($settings));
}

/**
 * @param  array<string, string>  $settings
 * @return array<string, string>
 */
function razorpaySettings(array $settings = []): array
{
    return [...['environment' => 'test', 'key_id' => RAZORPAY_KEY_ID, 'key_secret' => RAZORPAY_SECRET, 'webhook_secret' => RAZORPAY_HOOK_SECRET, 'host' => ''], ...$settings];
}

/**
 * @return array<string, mixed>
 */
function razorpayPayment(string $status, int $amount = 70850, string $id = 'pay_TEST1', string $currency = 'INR'): array
{
    return ['id' => $id, 'entity' => 'payment', 'amount' => $amount, 'currency' => $currency, 'status' => $status, 'order_id' => RAZORPAY_ORDER,
        'method' => 'upi', 'captured' => $status === 'captured', 'created_at' => 1790000000, 'vpa' => 'asha@okbank', 'email' => 'asha@example.com', 'contact' => '+919876543210',
        'card' => ['last4' => '4242'], 'error_code' => null];
}

/**
 * @param  list<array<string, mixed>>  $payments
 */
function razorpayFake(array $payments, int $orderAmount = 70850, string $orderCurrency = 'INR', mixed $capture = null): void
{
    Http::fake([
        '*/v1/orders/'.RAZORPAY_ORDER.'/payments' => Http::response(['entity' => 'collection', 'count' => count($payments), 'items' => $payments]),
        '*/v1/orders/'.RAZORPAY_ORDER => Http::response(['id' => RAZORPAY_ORDER, 'amount' => $orderAmount, 'currency' => $orderCurrency, 'status' => 'attempted']),
        '*/v1/payments/*/capture' => $capture ?? Http::response(razorpayPayment('captured')),
    ]);
}

function razorpayCaptureCalls(): int
{
    return Http::recorded()->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/capture'))->count();
}

function razorpayReturn(string $order = RAZORPAY_ORDER, string $payment = 'pay_TEST1', ?string $signature = null): Request
{
    return Request::create('/pay/return/razorpay?attempt='.RAZORPAY_ATTEMPT, 'POST', [
        'razorpay_order_id' => $order, 'razorpay_payment_id' => $payment,
        'razorpay_signature' => $signature ?? hash_hmac('sha256', $order.'|'.$payment, RAZORPAY_SECRET),
    ]);
}

/**
 * @return array<string, string>
 */
function razorpayReturnFields(string $order = RAZORPAY_ORDER, string $payment = 'pay_TEST1'): array
{
    return ['razorpay_order_id' => $order, 'razorpay_payment_id' => $payment, 'razorpay_signature' => hash_hmac('sha256', $order.'|'.$payment, RAZORPAY_SECRET)];
}

function razorpayHookBody(string $order = RAZORPAY_ORDER): string
{
    return (string) json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_TEST1', 'order_id' => $order, 'amount' => 1, 'status' => 'captured']]]]);
}

/**
 * @return array<string, string>
 */
function razorpayHookHeaders(?string $body = null, ?string $signature = null): array
{
    return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $body ?? razorpayHookBody(), RAZORPAY_HOOK_SECRET)];
}

function razorpayWebhook(string $order = RAZORPAY_ORDER, ?string $signature = null): Request
{
    $body = razorpayHookBody($order);

    return Request::create('/pay/webhook/razorpay', 'POST', [], [], [], razorpayHookHeaders($body, $signature), $body);
}

it('is marked untested and needs the customer\'s mobile', function () {
    expect(razorpay())->toBeInstanceOf(UntestedGateway::class)->toBeInstanceOf(NeedsCustomerDetails::class)
        ->and(RazorpayGateway::requiredDetails())->toBe(['phone'])
        ->and(RazorpayGateway::key())->toBe('razorpay')
        ->and(RazorpayGateway::currencies())->toBe(['INR']);
});

it('starts a payment with the attempt\'s amount, currency, order id and return url', function () {
    Http::fake(['*/v1/orders' => Http::response(['id' => 'order_NEW123', 'amount' => 70850, 'currency' => 'INR', 'status' => 'created'])]);

    $start = razorpay()->start($this->payment, 'https://isp.example/pay/return/razorpay?attempt=X');

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === RAZORPAY_API.'/v1/orders'
        && $request->method() === 'POST'
        && $request->header('Authorization') === ['Basic '.base64_encode(RAZORPAY_KEY_ID.':'.RAZORPAY_SECRET)]
        && $request->data() === ['amount' => 70850, 'currency' => 'INR', 'receipt' => RAZORPAY_ATTEMPT]);

    expect($start->kind)->toBe('form')
        ->and($start->url)->toBe('https://api.razorpay.com/v1/checkout/embedded')
        ->and($start->orderId)->toBe('order_NEW123')
        ->and($start->fields)->toMatchArray([
            'key_id' => RAZORPAY_KEY_ID, 'order_id' => 'order_NEW123', 'prefill[name]' => 'Asha Rao', 'prefill[contact]' => '+919876543210',
            'prefill[email]' => 'asha@example.com', 'callback_url' => 'https://isp.example/pay/return/razorpay?attempt=X', 'cancel_url' => 'https://isp.example/pay/return/razorpay?attempt=X',
        ])
        ->and($start->fields['name'])->not->toBe('')
        ->and($start->fields)->not->toHaveKey('key_secret');
});

it('uses the host in the settings and refuses an order without an id', function () {
    Http::fake(['*' => Http::response(['status' => 'created'])]);

    expect(fn () => razorpay(['host' => 'https://rzp.example.net/'])->start($this->payment, 'https://isp.example/r'))->toThrow(GatewayUnavailable::class);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://rzp.example.net/v1/orders');
});

it('reports paid only for the final success state with the amount and currency', function () {
    razorpayFake([razorpayPayment('captured', 100)]);

    $result = razorpay()->status($this->payment);

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET' && $request->url() === RAZORPAY_API.'/v1/orders/'.RAZORPAY_ORDER.'/payments');
    expect($result->status)->toBe(GatewayStatus::Paid)
        ->and($result->amount)->toBe('1.00')
        ->and($result->currency)->toBe('INR')
        ->and($result->txnId)->toBe('pay_TEST1')
        ->and($result->providerStatus)->toBe('captured')
        ->and($result->paidAt?->getTimestamp())->toBe(1790000000)
        ->and(razorpayCaptureCalls())->toBe(0);
});

it('reports failed never, and pending for anything that is not captured', function (array $payments) {
    razorpayFake($payments);

    expect(razorpay()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
    expect(razorpayCaptureCalls())->toBe(0);
})->with([
    'none yet' => [[]],
    'created' => [[razorpayPayment('created')]],
    'failed' => [[razorpayPayment('failed')]],
    'refunded' => [[razorpayPayment('refunded')]],
    'something new' => [[razorpayPayment('brand_new')]],
]);

it('captures an authorized payment once and counts it', function () {
    razorpayFake([razorpayPayment('authorized')]);

    $result = razorpay()->status($this->payment);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === RAZORPAY_API.'/v1/payments/pay_TEST1/capture'
        && $request->method() === 'POST' && $request->data() === ['amount' => 70850, 'currency' => 'INR']);
    expect($result->status)->toBe(GatewayStatus::Paid)->and($result->amount)->toBe('708.50')->and($result->currency)->toBe('INR')
        ->and(razorpayCaptureCalls())->toBe(1);
});

it('counts an already captured payment after the capture is refused', function () {
    Http::fake([
        '*/v1/orders/'.RAZORPAY_ORDER.'/payments' => Http::sequence()
            ->push(['items' => [razorpayPayment('authorized')]])
            ->push(['items' => [razorpayPayment('authorized')]])
            ->push(['items' => [razorpayPayment('captured')]]),
        '*/v1/orders/'.RAZORPAY_ORDER => Http::response(['amount' => 70850, 'currency' => 'INR']),
        '*/capture' => Http::response(['error' => ['description' => 'This payment has already been captured']], 400),
    ]);

    expect(razorpay()->status($this->payment)->status)->toBe(GatewayStatus::Paid);
    expect(razorpayCaptureCalls())->toBe(1);
});

it('stays pending when the capture is refused and nothing is captured', function () {
    razorpayFake([razorpayPayment('authorized')], capture: Http::response(['error' => []], 500));

    expect(razorpay()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
});

it('never captures when the amount or currency differs from the order or the attempt', function (int $orderAmount, string $orderCurrency, int $paymentAmount, string $paymentCurrency) {
    razorpayFake([razorpayPayment('authorized', $paymentAmount, currency: $paymentCurrency)], $orderAmount, $orderCurrency);

    expect(razorpay()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
    expect(razorpayCaptureCalls())->toBe(0);
})->with([
    'order lower' => [100, 'INR', 70850, 'INR'],
    'payment lower' => [70850, 'INR', 100, 'INR'],
    'order currency' => [70850, 'USD', 70850, 'INR'],
    'payment currency' => [70850, 'INR', 70850, 'USD'],
]);

it('lets a captured payment win and never captures a second one', function () {
    razorpayFake([razorpayPayment('authorized', id: 'pay_SECOND'), razorpayPayment('captured', id: 'pay_FIRST')]);

    $result = razorpay()->status($this->payment);

    expect($result->status)->toBe(GatewayStatus::Paid)->and($result->txnId)->toBe('pay_FIRST')->and(razorpayCaptureCalls())->toBe(0);
});

it('captures the oldest authorized payment when several arrive newest first', function () {
    razorpayFake([
        [...razorpayPayment('authorized', id: 'pay_NEW'), 'created_at' => 1790000500],
        [...razorpayPayment('authorized', id: 'pay_OLD'), 'created_at' => 1790000100],
    ]);

    razorpay()->status($this->payment);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === RAZORPAY_API.'/v1/payments/pay_OLD/capture');
    expect(razorpayCaptureCalls())->toBe(1);
});

it('does not capture when the in-lock re-read shows a captured payment', function () {
    Http::fake([
        '*/v1/orders/'.RAZORPAY_ORDER.'/payments' => Http::sequence()
            ->push(['items' => [razorpayPayment('authorized', id: 'pay_A')]])
            ->push(['items' => [razorpayPayment('authorized', id: 'pay_A'), razorpayPayment('captured', id: 'pay_B')]]),
    ]);

    $result = razorpay()->status($this->payment);

    expect($result->status)->toBe(GatewayStatus::Paid)->and($result->txnId)->toBe('pay_B')->and(razorpayCaptureCalls())->toBe(0);
});

it('stays pending without capturing when the capture lock of the order is held', function () {
    Sleep::fake(syncWithCarbon: true);
    razorpayFake([razorpayPayment('authorized')]);
    $lock = Cache::lock('razorpay-capture:'.RAZORPAY_ORDER, 30);
    expect($lock->get())->toBeTrue();

    expect(razorpay()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
    expect(razorpayCaptureCalls())->toBe(0);

    $lock->release();
});

it('ignores payments of another order and needs the saved order id', function () {
    razorpayFake([[...razorpayPayment('captured'), 'order_id' => 'order_OTHER'], array_diff_key(razorpayPayment('captured', id: 'pay_NOORDER'), ['order_id' => 1])]);
    expect(razorpay()->status($this->payment)->status)->toBe(GatewayStatus::Pending);

    $this->payment->update(['gateway_order_id' => null]);
    Http::fake();
    expect(razorpay()->status($this->payment->fresh())->status)->toBe(GatewayStatus::Pending);
    Http::assertNothingSent();
});

it('reports pending when the provider cannot be reached or answers an error', function (callable $fake) {
    $fake();

    expect(razorpay()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
})->with([
    'server error' => [fn () => Http::fake(['*' => Http::response(['error' => []], 500)])],
    'keys refused' => [fn () => Http::fake(['*' => Http::response(['error' => []], 401)])],
    'not reachable' => [fn () => Http::fake(['*' => Http::failedConnection()])],
    'no amount' => [fn () => razorpayFake([['id' => 'pay_X', 'status' => 'captured', 'currency' => 'INR']])],
]);

it('refuses a start the provider will not take, without the keys', function () {
    Http::fake(['*' => Http::response(['error' => ['description' => RAZORPAY_SECRET]], 400)]);

    try {
        razorpay()->start($this->payment, 'https://isp.example/r');
        $this->fail('Expected GatewayUnavailable');
    } catch (GatewayUnavailable $exception) {
        expect($exception->getMessage())->not->toContain(RAZORPAY_SECRET)->not->toContain(RAZORPAY_KEY_ID);
    }
});

it('accepts a genuine return and webhook and names the attempt', function () {
    $cancelled = Request::create('/pay/return/razorpay', 'GET', ['attempt' => RAZORPAY_ATTEMPT]);

    expect(razorpay()->verify(razorpayReturn()))->toBe(RAZORPAY_ATTEMPT)
        ->and(razorpay()->verify(razorpayWebhook()))->toBe(RAZORPAY_ATTEMPT)
        ->and(razorpay()->verify($cancelled))->toBe(RAZORPAY_ATTEMPT);
});

it('rejects a bad signature, an unknown attempt and another gateway\'s attempt', function () {
    $other = OnlinePayment::factory()->create(['public_id' => 'OTHERGATEWAYATTEMPT00002', 'gateway' => 'fake', 'gateway_order_id' => 'order_OTHERGW']);
    $get = fn (array $query): Request => Request::create('/pay/return/razorpay', 'GET', $query);
    $post = fn (array $fields): Request => Request::create('/pay/return/razorpay?attempt='.RAZORPAY_ATTEMPT, 'POST', $fields);
    $fields = razorpayReturnFields();
    $hookWithoutHeader = Request::create('/pay/webhook/razorpay', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], razorpayHookBody());
    $tampered = Request::create('/pay/webhook/razorpay', 'POST', [], [], [], razorpayHookHeaders(razorpayHookBody()), str_replace('"amount":1', '"amount":2', razorpayHookBody()));

    expect(razorpay()->verify(razorpayReturn(signature: 'deadbeef')))->toBeNull()
        ->and(razorpay()->verify(razorpayReturn(payment: 'pay_OTHER', signature: $fields['razorpay_signature'])))->toBeNull()
        ->and(razorpay()->verify($post(['razorpay_order_id' => $fields['razorpay_order_id'], 'razorpay_signature' => $fields['razorpay_signature']])))->toBeNull()
        ->and(razorpay()->verify($post(['razorpay_order_id' => RAZORPAY_ORDER])))->toBeNull()
        ->and(razorpay()->verify($post([...$fields, 'razorpay_signature' => ''])))->toBeNull()
        ->and(razorpay()->verify($post([...$fields, 'razorpay_order_id' => ['x']])))->toBeNull()
        ->and(razorpay()->verify(razorpayReturn(order: 'order_UNKNOWN')))->toBeNull()
        ->and(razorpay()->verify(razorpayReturn(order: 'order_OTHERGW')))->toBeNull()
        ->and(razorpay(['key_secret' => ''])->verify($post(['razorpay_order_id' => 'o', 'razorpay_payment_id' => 'p', 'razorpay_signature' => hash_hmac('sha256', 'o|p', '')])))->toBeNull()
        ->and(razorpay()->verify($get(['attempt' => 'NOSUCHATTEMPT'])))->toBeNull()
        ->and(razorpay()->verify($get(['attempt' => $other->public_id])))->toBeNull()
        ->and(razorpay()->verify($get([])))->toBeNull()
        ->and(razorpay()->verify($get(['attempt' => ['x']])))->toBeNull()
        ->and(razorpay()->verify(razorpayWebhook(signature: 'deadbeef')))->toBeNull()
        ->and(razorpay()->verify(razorpayWebhook(signature: '')))->toBeNull()
        ->and(razorpay()->verify($hookWithoutHeader))->toBeNull()
        ->and(razorpay()->verify($tampered))->toBeNull()
        ->and(razorpay()->verify(razorpayWebhook('order_UNKNOWN')))->toBeNull()
        ->and(razorpay()->verify(razorpayWebhook('order_OTHERGW')))->toBeNull()
        ->and(razorpay(['webhook_secret' => ''])->verify(razorpayWebhook(signature: hash_hmac('sha256', razorpayHookBody(), ''))))->toBeNull();

    $queryOnlyHook = Request::create('/pay/webhook/razorpay?attempt='.RAZORPAY_ATTEMPT, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], razorpayHookBody());
    expect(razorpay()->verify($queryOnlyHook))->toBeNull();
});

describe('through the routes', function () {
    beforeEach(function () {
        GatewaySetting::query()->updateOrCreate(['gateway' => 'razorpay'], ['enabled' => true, 'settings' => razorpaySettings()]);
    });

    it('records nothing when a genuine-looking success return meets a status that is not paid', function (array $payments, int $orderAmount, string $orderCurrency, OnlinePaymentStatus $expected) {
        razorpayFake($payments, $orderAmount, $orderCurrency);

        $this->post(route('pay.return', ['razorpay', 'attempt' => RAZORPAY_ATTEMPT]), razorpayReturnFields());
        $this->call('POST', route('pay.webhook', 'razorpay'), [], [], [], razorpayHookHeaders(), razorpayHookBody())->assertOk();

        expect(Payment::query()->count())->toBe(0)
            ->and($this->payment->fresh()->status)->toBe($expected)
            ->and(razorpayCaptureCalls())->toBe(0);
    })->with([
        'created' => [[razorpayPayment('created')], 70850, 'INR', OnlinePaymentStatus::Pending],
        'failed' => [[razorpayPayment('failed')], 70850, 'INR', OnlinePaymentStatus::Pending],
        'authorized for a lower amount' => [[razorpayPayment('authorized', 100)], 70850, 'INR', OnlinePaymentStatus::Pending],
        'authorized in another currency' => [[razorpayPayment('authorized', 70850, currency: 'USD')], 70850, 'INR', OnlinePaymentStatus::Pending],
        'order lower than the attempt' => [[razorpayPayment('authorized')], 100, 'INR', OnlinePaymentStatus::Pending],
    ]);

    it('marks the attempt for attention when Razorpay captured a lower amount', function () {
        razorpayFake([razorpayPayment('captured', 100)]);

        $this->post(route('pay.return', ['razorpay', 'attempt' => RAZORPAY_ATTEMPT]), razorpayReturnFields());

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::NeedsAttention)->and(Payment::query()->count())->toBe(0);
    });

    it('records nothing for a captured payment in another currency', function () {
        razorpayFake([razorpayPayment('captured', currency: 'USD')]);

        $this->post(route('pay.return', ['razorpay', 'attempt' => RAZORPAY_ATTEMPT]), razorpayReturnFields());

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::NeedsAttention)->and(Payment::query()->count())->toBe(0);
    });

    it('does not mark anything on a cancelled return, only re-checks', function () {
        razorpayFake([razorpayPayment('created')]);

        $this->get(route('pay.return', ['razorpay', 'attempt' => RAZORPAY_ATTEMPT]));

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending)->and(Payment::query()->count())->toBe(0);
    });

    it('ignores a webhook with a wrong signature', function () {
        razorpayFake([razorpayPayment('captured')]);

        $this->call('POST', route('pay.webhook', 'razorpay'), [], [], [], razorpayHookHeaders(signature: 'nope'), razorpayHookBody())->assertNotFound();

        Http::assertNothingSent();
        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending);
    });

    it('keeps keys, tokens and signatures out of the stored answer', function () {
        razorpayFake([razorpayPayment('created')]);

        $this->post(route('pay.return', ['razorpay', 'attempt' => RAZORPAY_ATTEMPT]), razorpayReturnFields());

        $stored = (string) json_encode($this->payment->fresh()->gateway_response);
        expect($stored)->toContain('pay_TEST1')
            ->not->toContain(RAZORPAY_SECRET)->not->toContain(RAZORPAY_HOOK_SECRET)->not->toContain(RAZORPAY_KEY_ID)
            ->not->toContain(razorpayReturnFields()['razorpay_signature'])
            ->not->toContain('Authorization')->not->toContain('4242')->not->toContain('okbank')->not->toContain('9876543210');
    });
});
