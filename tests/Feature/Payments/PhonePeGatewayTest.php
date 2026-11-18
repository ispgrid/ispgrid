<?php

use App\Contracts\UntestedGateway;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\GatewayUnavailable;
use App\Models\GatewaySetting;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Payments\GatewayStatus;
use App\Payments\PhonePe\PhonePeGateway;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const PHONEPE_ID = 'TESTCLIENT_1';
const PHONEPE_SECRET = 'client-secret-value-9f3a';
const PHONEPE_WEBHOOK_USER = 'hookuser';
const PHONEPE_WEBHOOK_PASS = 'hook-pass-77';
const PHONEPE_SANDBOX = 'https://api-preprod.phonepe.com/apis/pg-sandbox';
const PHONEPE_ORDER = '01JQUICKPAYTESTORDER000001';

beforeEach(function () {
    Cache::flush();
    $this->payment = OnlinePayment::factory()->create(['public_id' => PHONEPE_ORDER, 'gateway' => 'phonepe', 'amount' => '708.50', 'currency' => 'INR']);
});

/**
 * @param  array<string, string>  $settings
 * @return array<string, string>
 */
function phonepeSettings(array $settings = []): array
{
    return [...['environment' => 'test', 'client_id' => PHONEPE_ID, 'client_version' => '1', 'client_secret' => PHONEPE_SECRET,
        'webhook_username' => PHONEPE_WEBHOOK_USER, 'webhook_password' => PHONEPE_WEBHOOK_PASS, 'host' => ''], ...$settings];
}

/**
 * @param  array<string, string>  $settings
 */
function phonepe(array $settings = []): PhonePeGateway
{
    return new PhonePeGateway(phonepeSettings($settings));
}

/**
 * @return array<string, mixed>
 */
function phonepeToken(string $token = 'TOKEN-abc-123'): array
{
    return ['access_token' => $token, 'token_type' => 'O-Bearer', 'issued_at' => time(), 'expires_at' => time() + 3600];
}

/**
 * @param  array<string, mixed>  $order
 */
function fakePhonepe(array $order, int $orderHttp = 200): void
{
    Http::fake([
        '*/v1/oauth/token' => Http::response(phonepeToken()),
        '*/checkout/v2/order/*' => Http::response($order, $orderHttp),
    ]);
}

/**
 * @return array<string, mixed>
 */
function phonepeOrder(string $state, int $amount = 70850): array
{
    return ['orderId' => 'OMO123', 'state' => $state, 'amount' => $amount, 'expireAt' => 1790000000000, 'paymentDetails' => [[
        'paymentMode' => 'NET_BANKING', 'timestamp' => 1790000000000, 'amount' => $amount, 'transactionId' => 'OM456', 'state' => $state === 'COMPLETED' ? 'COMPLETED' : 'FAILED',
        'rail' => ['type' => 'PG_RAIL'], 'instrument' => ['type' => 'NET_BANKING', 'bankId' => 'SBIN', 'accountNumber' => 'XXXX1234'],
    ]]];
}

function phonepeHookHeaders(?string $authorization = null): array
{
    return ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $authorization ?? hash('sha256', PHONEPE_WEBHOOK_USER.':'.PHONEPE_WEBHOOK_PASS)];
}

function phonepeHookBody(string $order = PHONEPE_ORDER): string
{
    return (string) json_encode(['event' => 'checkout.order.completed', 'payload' => ['orderId' => 'OMO123', 'merchantOrderId' => $order, 'state' => 'COMPLETED', 'amount' => 1]]);
}

function phonepeWebhook(string $order = PHONEPE_ORDER, ?string $authorization = null): Request
{
    return Request::create('/pay/webhook/phonepe', 'POST', [], [], [], phonepeHookHeaders($authorization), phonepeHookBody($order));
}

function phonepeTokenCalls(): int
{
    return Http::recorded()->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'oauth/token'))->count();
}

it('is marked untested until a live payment has gone through', function () {
    expect(phonepe())->toBeInstanceOf(UntestedGateway::class)
        ->and(PhonePeGateway::key())->toBe('phonepe')
        ->and(PhonePeGateway::currencies())->toBe(['INR']);
});

it('starts a payment with the attempt\'s amount, currency, order id and return url', function () {
    Http::fake([
        '*/v1/oauth/token' => Http::response(phonepeToken()),
        '*/checkout/v2/pay' => Http::response(['orderId' => 'OMO123', 'state' => 'PENDING', 'expireAt' => 1790000000000, 'redirectUrl' => 'https://mercury-uat.phonepe.com/transact/uat_v2?token=abc']),
    ]);

    $start = phonepe()->start($this->payment, 'https://isp.example/pay/return/phonepe?attempt=X');

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === PHONEPE_SANDBOX.'/v1/oauth/token'
        && $request->method() === 'POST'
        && $request->isForm()
        && $request->data() === ['client_id' => PHONEPE_ID, 'client_version' => '1', 'client_secret' => PHONEPE_SECRET, 'grant_type' => 'client_credentials']);
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === PHONEPE_SANDBOX.'/checkout/v2/pay'
        && $request->header('Authorization') === ['O-Bearer TOKEN-abc-123']
        && $request['merchantOrderId'] === PHONEPE_ORDER
        && $request['amount'] === 70850
        && $request['expireAfter'] === 1200
        && $request['paymentFlow'] === ['type' => 'PG_CHECKOUT', 'merchantUrls' => ['redirectUrl' => 'https://isp.example/pay/return/phonepe?attempt=X']]);

    expect($start->kind)->toBe('redirect')
        ->and($start->url)->toBe('https://mercury-uat.phonepe.com/transact/uat_v2?token=abc')
        ->and($start->orderId)->toBe('OMO123');
});

it('uses the live hosts, or the host in the settings', function () {
    Http::fake([
        '*/v1/oauth/token' => Http::response(phonepeToken()),
        '*/checkout/v2/pay' => Http::response(['orderId' => 'O', 'state' => 'PENDING', 'redirectUrl' => 'https://pay.example/x']),
    ]);

    phonepe(['environment' => 'live'])->start($this->payment, 'https://isp.example/r');
    phonepe(['host' => 'https://pg.example.net/apis/pg/'])->start($this->payment, 'https://isp.example/r');

    $urls = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all();
    expect($urls)->toBe([
        'https://api.phonepe.com/apis/identity-manager/v1/oauth/token',
        'https://api.phonepe.com/apis/pg/checkout/v2/pay',
        'https://pg.example.net/apis/pg/v1/oauth/token',
        'https://pg.example.net/apis/pg/checkout/v2/pay',
    ]);
});

it('keeps the token per client and environment until a minute before it expires', function () {
    fakePhonepe(phonepeOrder('PENDING'));

    phonepe()->status($this->payment);
    phonepe()->status($this->payment);
    expect(phonepeTokenCalls())->toBe(1);

    phonepe(['client_id' => 'OTHER'])->status($this->payment);
    phonepe(['environment' => 'live'])->status($this->payment);
    expect(phonepeTokenCalls())->toBe(3);

    $this->travel(3500)->seconds();
    phonepe()->status($this->payment);
    expect(phonepeTokenCalls())->toBe(3);

    $this->travel(100)->seconds();
    phonepe()->status($this->payment);
    expect(phonepeTokenCalls())->toBe(4);
});

it('reports paid only for the final success state with the amount and currency', function () {
    fakePhonepe(phonepeOrder('COMPLETED'));

    $result = phonepe()->status($this->payment);

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
        && $request->url() === PHONEPE_SANDBOX.'/checkout/v2/order/'.PHONEPE_ORDER.'/status?details=true'
        && $request->header('Authorization') === ['O-Bearer TOKEN-abc-123']);

    expect($result->status)->toBe(GatewayStatus::Paid)
        ->and($result->amount)->toBe('708.50')
        ->and($result->currency)->toBe('INR')
        ->and($result->txnId)->toBe('OM456')
        ->and($result->providerStatus)->toBe('COMPLETED')
        ->and($result->paidAt?->getTimestamp())->toBe(1790000000);
});

it('takes the amount from the answer, not from the attempt', function () {
    fakePhonepe(phonepeOrder('COMPLETED', 100));

    expect(phonepe()->status($this->payment))->status->toBe(GatewayStatus::Paid)->amount->toBe('1.00');
});

it('reports failed for a final failure state and pending for anything else', function (string $state, GatewayStatus $expected) {
    fakePhonepe(phonepeOrder($state));

    expect(phonepe()->status($this->payment))->status->toBe($expected)->providerStatus->toBe($state);
})->with([
    ['FAILED', GatewayStatus::Failed],
    ['PENDING', GatewayStatus::Pending],
    ['SOMETHING_NEW', GatewayStatus::Pending],
]);

it('never takes a completed order of another PhonePe order as paid', function () {
    $this->payment->update(['gateway_order_id' => 'OMO-MINE']);
    fakePhonepe(phonepeOrder('COMPLETED'));

    expect(phonepe()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
});

it('fetches a fresh token and retries once when the cached one is refused', function () {
    Cache::put('phonepe.token.test.'.sha1(PHONEPE_ID.'|'.PHONEPE_SANDBOX), 'STALE', now()->addMinutes(30));
    Http::fake([
        '*/v1/oauth/token' => Http::response(phonepeToken('FRESH')),
        '*/checkout/v2/order/*' => Http::sequence()->push(['code' => 'UNAUTHORIZED'], 401)->push(phonepeOrder('COMPLETED')),
    ]);

    expect(phonepe()->status($this->payment)->status)->toBe(GatewayStatus::Paid);

    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/order/') && $request->header('Authorization') === ['O-Bearer STALE']);
    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/order/') && $request->header('Authorization') === ['O-Bearer FRESH']);
    expect(phonepeTokenCalls())->toBe(1);
});

it('gives up after a second refusal', function () {
    Http::fake(['*/v1/oauth/token' => Http::response(phonepeToken()), '*/checkout/v2/*' => Http::response(['code' => 'UNAUTHORIZED'], 401)]);

    expect(phonepe()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
    expect(phonepeTokenCalls())->toBe(2);

    Cache::flush();
    expect(fn () => phonepe()->start($this->payment, 'https://isp.example/r'))->toThrow(GatewayUnavailable::class);
});

it('reports pending when the provider cannot be reached or answers an error', function (callable $fake) {
    $fake();

    expect(phonepe()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
})->with([
    'server error' => [fn () => fakePhonepe(['code' => 'INTERNAL_SERVER_ERROR'], 500)],
    'token refused' => [fn () => Http::fake(['*' => Http::response(['code' => 'UNAUTHORIZED'], 401)])],
    'not reachable' => [fn () => Http::fake(['*' => Http::failedConnection()])],
    'no amount' => [fn () => fakePhonepe(['orderId' => 'OMO123', 'state' => 'COMPLETED'])],
]);

it('refuses a start the provider will not take, without the keys', function () {
    Http::fake(['*/v1/oauth/token' => Http::response(phonepeToken()), '*/checkout/v2/pay' => Http::response(['code' => 'BAD_REQUEST', 'secret' => PHONEPE_SECRET], 400)]);

    try {
        phonepe()->start($this->payment, 'https://isp.example/r');
        $this->fail('Expected GatewayUnavailable');
    } catch (GatewayUnavailable $exception) {
        expect($exception->getMessage())->not->toContain(PHONEPE_SECRET)->not->toContain('TOKEN-abc-123');
    }

    Http::fake(['*' => Http::response(['access_token' => '', 'expires_at' => time() + 100])]);
    Cache::flush();
    expect(fn () => phonepe()->start($this->payment, 'https://isp.example/r'))->toThrow(GatewayUnavailable::class);
});

it('accepts a genuine return and webhook and names the attempt', function () {
    expect(phonepe()->verify(Request::create('/pay/return/phonepe', 'GET', ['attempt' => PHONEPE_ORDER])))->toBe(PHONEPE_ORDER)
        ->and(phonepe()->verify(phonepeWebhook()))->toBe(PHONEPE_ORDER);
});

it('rejects a bad signature, an unknown attempt and another gateway\'s attempt', function () {
    $other = OnlinePayment::factory()->create(['public_id' => 'OTHERGATEWAYATTEMPT00001', 'gateway' => 'fake']);
    $return = fn (array $query): Request => Request::create('/pay/return/phonepe', 'GET', $query);

    expect(phonepe()->verify($return(['attempt' => 'NOSUCHATTEMPT'])))->toBeNull()
        ->and(phonepe()->verify($return(['attempt' => $other->public_id])))->toBeNull()
        ->and(phonepe()->verify($return([])))->toBeNull()
        ->and(phonepe()->verify($return(['attempt' => ['x']])))->toBeNull()
        ->and(phonepe()->verify(phonepeWebhook(authorization: hash('sha256', 'hookuser:wrong'))))->toBeNull()
        ->and(phonepe()->verify(phonepeWebhook(authorization: '')))->toBeNull()
        ->and(phonepe()->verify(phonepeWebhook('NOSUCHATTEMPT')))->toBeNull()
        ->and(phonepe()->verify(phonepeWebhook($other->public_id)))->toBeNull()
        ->and(phonepe(['webhook_username' => '', 'webhook_password' => ''])->verify(phonepeWebhook(authorization: hash('sha256', ':'))))->toBeNull();

    $noHeader = Request::create('/pay/webhook/phonepe?attempt='.PHONEPE_ORDER, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], phonepeHookBody());
    expect(phonepe()->verify($noHeader))->toBeNull();
});

describe('through the routes', function () {
    beforeEach(function () {
        GatewaySetting::query()->updateOrCreate(['gateway' => 'phonepe'], ['enabled' => true, 'settings' => phonepeSettings()]);
    });

    it('records nothing when a genuine-looking success return meets a status that is not paid', function (array $order, OnlinePaymentStatus $expected) {
        fakePhonepe($order);

        $this->get(route('pay.return', ['phonepe', 'attempt' => PHONEPE_ORDER, 'code' => 'PAYMENT_SUCCESS', 'state' => 'COMPLETED', 'amount' => 70850]));
        $this->call('POST', route('pay.webhook', 'phonepe'), [], [], [], phonepeHookHeaders(), phonepeHookBody())->assertOk();

        expect(Payment::query()->count())->toBe(0)
            ->and($this->payment->fresh()->status)->toBe($expected);
    })->with([
        'pending' => [phonepeOrder('PENDING'), OnlinePaymentStatus::Pending],
        'failed' => [phonepeOrder('FAILED'), OnlinePaymentStatus::Failed],
    ]);

    it('marks the attempt for attention when the provider took a lower amount', function () {
        fakePhonepe(phonepeOrder('COMPLETED', 100));

        $this->get(route('pay.return', ['phonepe', 'attempt' => PHONEPE_ORDER]));

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::NeedsAttention)->and(Payment::query()->count())->toBe(0);
    });

    it('records nothing for a currency other than INR', function () {
        fakePhonepe([...phonepeOrder('COMPLETED'), 'currency' => 'USD']);

        $this->get(route('pay.return', ['phonepe', 'attempt' => PHONEPE_ORDER]));

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::NeedsAttention)->and(Payment::query()->count())->toBe(0);
    });

    it('ignores a webhook with a wrong authorization', function () {
        fakePhonepe(phonepeOrder('COMPLETED'));

        $this->call('POST', route('pay.webhook', 'phonepe'), [], [], [], phonepeHookHeaders('nope'), phonepeHookBody())->assertNotFound();

        Http::assertNothingSent();
        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending);
    });

    it('keeps keys, tokens and signatures out of the stored answer', function () {
        fakePhonepe(phonepeOrder('PENDING'));

        $this->get(route('pay.return', ['phonepe', 'attempt' => PHONEPE_ORDER]));

        $stored = (string) json_encode($this->payment->fresh()->gateway_response);
        expect($stored)->toContain('OMO123')
            ->not->toContain('TOKEN-abc-123')->not->toContain(PHONEPE_SECRET)->not->toContain(PHONEPE_WEBHOOK_PASS)
            ->not->toContain(hash('sha256', PHONEPE_WEBHOOK_USER.':'.PHONEPE_WEBHOOK_PASS))
            ->not->toContain('Authorization')->not->toContain('XXXX1234');
    });
});
