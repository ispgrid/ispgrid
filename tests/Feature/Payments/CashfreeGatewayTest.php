<?php

use App\Contracts\NeedsCustomerDetails;
use App\Contracts\UntestedGateway;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\GatewayUnavailable;
use App\Models\Account;
use App\Models\GatewaySetting;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Payments\Cashfree\CashfreeGateway;
use App\Payments\GatewayStart;
use App\Payments\GatewayStatus;
use App\Support\PublicPage;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const CASHFREE_ID = 'TEST_CLIENT_ID_01';
const CASHFREE_SECRET = 'cfsk_ma_test_secret_value_7d1';
const CASHFREE_ATTEMPT = '01JQUICKPAYTESTORDER000003';
const CASHFREE_SANDBOX = 'https://sandbox.cashfree.com';

beforeEach(function () {
    $this->payment = OnlinePayment::factory()->create([
        'public_id' => CASHFREE_ATTEMPT, 'gateway' => 'cashfree', 'amount' => '708.50', 'currency' => 'INR',
        'account_id' => Account::factory()->create(['fullname' => 'Asha Rao', 'mobile' => '9876543210', 'email' => 'asha@example.com']),
    ]);
});

/**
 * @param  array<string, string>  $settings
 * @return array<string, string>
 */
function cashfreeSettings(array $settings = []): array
{
    return [...['environment' => 'test', 'client_id' => CASHFREE_ID, 'client_secret' => CASHFREE_SECRET, 'host' => ''], ...$settings];
}

/**
 * @param  array<string, string>  $settings
 */
function cashfree(array $settings = []): CashfreeGateway
{
    return new CashfreeGateway(cashfreeSettings($settings));
}

/**
 * @return array<string, mixed>
 */
function cashfreeOrder(string $state, int|float|string $amount = 708.5, string $currency = 'INR'): array
{
    return ['cf_order_id' => 2149460581, 'order_id' => CASHFREE_ATTEMPT, 'order_status' => $state, 'order_amount' => $amount, 'order_currency' => $currency,
        'payment_session_id' => 'session_SECRETTOKEN', 'customer_details' => ['customer_phone' => '9876543210', 'customer_email' => 'asha@example.com'],
        'order_meta' => ['return_url' => 'https://isp.example/r']];
}

/**
 * @param  array<string, mixed>  $order
 */
function fakeCashfree(array $order, int $http = 200): void
{
    Http::fake(['*/pg/orders*' => Http::response($order, $http)]);
}

function cashfreeHookBody(string $order = CASHFREE_ATTEMPT): string
{
    return (string) json_encode(['type' => 'PAYMENT_SUCCESS_WEBHOOK', 'data' => ['order' => ['order_id' => $order, 'order_amount' => 1, 'order_currency' => 'INR'], 'payment' => ['payment_status' => 'SUCCESS']]]);
}

/**
 * @return array<string, string>
 */
function cashfreeHookHeaders(string $body, ?string $signature = null, string $timestamp = '1790000000123'): array
{
    return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
        'HTTP_X_WEBHOOK_SIGNATURE' => $signature ?? base64_encode(hash_hmac('sha256', $timestamp.$body, CASHFREE_SECRET, true))];
}

function cashfreeWebhook(string $order = CASHFREE_ATTEMPT, ?string $signature = null): Request
{
    $body = cashfreeHookBody($order);

    return Request::create('/pay/webhook/cashfree', 'POST', [], [], [], cashfreeHookHeaders($body, $signature), $body);
}

it('is marked untested and asks for the phone', function () {
    expect(cashfree())->toBeInstanceOf(UntestedGateway::class)->toBeInstanceOf(NeedsCustomerDetails::class)
        ->and(CashfreeGateway::key())->toBe('cashfree')
        ->and(CashfreeGateway::currencies())->toBe(['INR'])
        ->and(CashfreeGateway::requiredDetails())->toBe(['phone']);
});

it('starts a payment with the attempt\'s amount, currency, order id and return url', function () {
    fakeCashfree(cashfreeOrder('ACTIVE'));

    $start = cashfree()->start($this->payment, 'https://isp.example/pay/return/cashfree?attempt=X');

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === CASHFREE_SANDBOX.'/pg/orders'
        && $request->method() === 'POST'
        && $request->header('x-api-version') === ['2025-01-01']
        && $request->header('x-client-id') === [CASHFREE_ID]
        && $request->header('x-client-secret') === [CASHFREE_SECRET]
        && $request['order_id'] === CASHFREE_ATTEMPT
        && $request['order_amount'] === 708.5
        && str_contains($request->body(), '"order_amount":708.5,')
        && $request['order_currency'] === 'INR'
        && $request['customer_details'] === ['customer_id' => 'CUST'.$this->payment->account_id, 'customer_phone' => '9876543210', 'customer_name' => 'Asha Rao', 'customer_email' => 'asha@example.com']
        && $request['order_meta'] === ['return_url' => 'https://isp.example/pay/return/cashfree?attempt=X']);

    expect($start->kind)->toBe('script')
        ->and($start->url)->toBe('https://sdk.cashfree.com/js/v3/cashfree.js')
        ->and($start->fields)->toBe(['init' => 'cashfree', 'mode' => 'sandbox', 'session' => 'session_SECRETTOKEN'])
        ->and($start->orderId)->toBe(CASHFREE_ATTEMPT);
});

it('uses the live host and production mode, or the host in the settings', function () {
    fakeCashfree(cashfreeOrder('ACTIVE'));

    $live = cashfree(['environment' => 'live'])->start($this->payment, 'https://isp.example/r');
    cashfree(['host' => 'https://pg.example.net/'])->start($this->payment, 'https://isp.example/r');

    expect($live->fields['mode'])->toBe('production');
    expect(Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all())->toBe(['https://api.cashfree.com/pg/orders', 'https://pg.example.net/pg/orders']);
});

it('sends no email when there is none, and refuses a customer without a mobile', function () {
    fakeCashfree(cashfreeOrder('ACTIVE'));
    $this->payment->account->update(['email' => '']);

    cashfree()->start($this->payment, 'https://isp.example/r');
    Http::assertSent(fn (ClientRequest $request): bool => ! array_key_exists('customer_email', $request['customer_details']));

    $this->payment->account->update(['mobile' => '12345']);
    expect(fn () => cashfree()->start($this->payment->fresh(), 'https://isp.example/r'))->toThrow(GatewayUnavailable::class);
});

it('sends a name only when it is 3 to 100 characters', function () {
    fakeCashfree(cashfreeOrder('ACTIVE'));
    $this->payment->account->update(['fullname' => 'Al']);
    $this->payment->account->update(['username' => 'ab']);

    cashfree()->start($this->payment->fresh(), 'https://isp.example/r');

    Http::assertSent(fn (ClientRequest $request): bool => ! array_key_exists('customer_name', $request['customer_details']));
});

it('reads a float amount by its shortest form whatever the precision setting', function () {
    $before = ini_get('precision');
    ini_set('precision', '17');
    fakeCashfree(cashfreeOrder('PAID', 708.1));

    $result = cashfree()->status($this->payment);
    ini_set('precision', (string) $before);

    expect($result->status)->toBe(GatewayStatus::Paid)->and($result->amount)->toBe('708.10');
});

it('refuses a start without a payment session, and keeps the keys out of the message', function () {
    Http::fake(['*' => Http::response(['order_id' => CASHFREE_ATTEMPT, 'order_status' => 'ACTIVE'])]);
    expect(fn () => cashfree()->start($this->payment, 'https://isp.example/r'))->toThrow(GatewayUnavailable::class);

    Http::fake(['*' => Http::response(['message' => 'bad', 'key' => CASHFREE_SECRET], 400)]);
    try {
        cashfree()->start($this->payment, 'https://isp.example/r');
        $this->fail('Expected GatewayUnavailable');
    } catch (GatewayUnavailable $exception) {
        expect($exception->getMessage())->not->toContain(CASHFREE_SECRET);
    }
});

it('reports paid only for the final success state with the amount and currency', function () {
    fakeCashfree(cashfreeOrder('PAID', 708.5));

    $result = cashfree()->status($this->payment);

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
        && $request->url() === CASHFREE_SANDBOX.'/pg/orders/'.CASHFREE_ATTEMPT
        && $request->header('x-api-version') === ['2025-01-01']);

    expect($result->status)->toBe(GatewayStatus::Paid)
        ->and($result->amount)->toBe('708.50')
        ->and($result->currency)->toBe('INR')
        ->and($result->txnId)->toBe('2149460581')
        ->and($result->providerStatus)->toBe('PAID');
});

it('takes the amount and currency from the answer, not from the attempt', function () {
    fakeCashfree(cashfreeOrder('PAID', 1, 'USD'));

    expect(cashfree()->status($this->payment))->status->toBe(GatewayStatus::Paid)->amount->toBe('1.00')->currency->toBe('USD');
});

it('reports failed for a final failure state and pending for anything else', function (string $state, GatewayStatus $expected) {
    fakeCashfree(cashfreeOrder($state));

    expect(cashfree()->status($this->payment))->status->toBe($expected)->providerStatus->toBe($state);
})->with([
    ['EXPIRED', GatewayStatus::Failed],
    ['TERMINATED', GatewayStatus::Failed],
    ['ACTIVE', GatewayStatus::Pending],
    ['TERMINATION_REQUESTED', GatewayStatus::Pending],
    ['SOMETHING_NEW', GatewayStatus::Pending],
]);

it('never takes a paid answer for another order as paid', function () {
    fakeCashfree([...cashfreeOrder('PAID'), 'order_id' => 'SOMEOTHERORDER']);

    expect(cashfree()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
});

it('reports pending when the provider cannot be reached or answers an error', function (callable $fake) {
    $fake();

    expect(cashfree()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
})->with([
    'server error' => [fn () => fakeCashfree(['message' => 'boom'], 500)],
    'refused' => [fn () => fakeCashfree(['message' => 'auth'], 401)],
    'not reachable' => [fn () => Http::fake(['*' => Http::failedConnection()])],
    'no amount' => [fn () => fakeCashfree(['order_id' => CASHFREE_ATTEMPT, 'order_status' => 'PAID', 'order_currency' => 'INR'])],
    'no currency' => [fn () => fakeCashfree(['order_id' => CASHFREE_ATTEMPT, 'order_status' => 'PAID', 'order_amount' => 708.5])],
    'amount not a number' => [fn () => fakeCashfree(cashfreeOrder('PAID', 'lots'))],
]);

it('accepts a genuine return and webhook and names the attempt', function () {
    expect(cashfree()->verify(Request::create('/pay/return/cashfree', 'GET', ['attempt' => CASHFREE_ATTEMPT])))->toBe(CASHFREE_ATTEMPT)
        ->and(cashfree()->verify(cashfreeWebhook()))->toBe(CASHFREE_ATTEMPT);
});

it('rejects a bad signature, an unknown attempt and another gateway\'s attempt', function () {
    $other = OnlinePayment::factory()->create(['public_id' => 'OTHERGATEWAYATTEMPT00001', 'gateway' => 'fake']);
    $return = fn (array $query): Request => Request::create('/pay/return/cashfree', 'GET', $query);
    $body = cashfreeHookBody();

    expect(cashfree()->verify($return(['attempt' => 'NOSUCHATTEMPT'])))->toBeNull()
        ->and(cashfree()->verify($return(['attempt' => $other->public_id])))->toBeNull()
        ->and(cashfree()->verify($return([])))->toBeNull()
        ->and(cashfree()->verify($return(['attempt' => ['x']])))->toBeNull()
        ->and(cashfree()->verify(cashfreeWebhook(signature: base64_encode('wrong'))))->toBeNull()
        ->and(cashfree()->verify(cashfreeWebhook(signature: '')))->toBeNull()
        ->and(cashfree()->verify(cashfreeWebhook('NOSUCHATTEMPT')))->toBeNull()
        ->and(cashfree()->verify(cashfreeWebhook($other->public_id)))->toBeNull()
        ->and(cashfree(['client_secret' => ''])->verify(Request::create('/pay/webhook/cashfree', 'POST', [], [], [], cashfreeHookHeaders($body, base64_encode(hash_hmac('sha256', '1790000000123'.$body, '', true))), $body)))->toBeNull();

    $noSignature = Request::create('/pay/webhook/cashfree?attempt='.CASHFREE_ATTEMPT, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => '1790000000123'], $body);
    $noTimestamp = Request::create('/pay/webhook/cashfree', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, CASHFREE_SECRET, true))], $body);
    $tampered = Request::create('/pay/webhook/cashfree', 'POST', [], [], [], cashfreeHookHeaders($body), str_replace('"order_amount":1', '"order_amount":2', $body));

    expect(cashfree()->verify($noSignature))->toBeNull()
        ->and(cashfree()->verify($noTimestamp))->toBeNull()
        ->and(cashfree()->verify($tampered))->toBeNull();
});

describe('through the routes', function () {
    beforeEach(function () {
        GatewaySetting::query()->updateOrCreate(['gateway' => 'cashfree'], ['enabled' => true, 'settings' => cashfreeSettings()]);
    });

    it('records nothing when a genuine-looking success return meets a status that is not paid', function (array $order, OnlinePaymentStatus $expected) {
        fakeCashfree($order);
        $body = cashfreeHookBody();

        $this->get(route('pay.return', ['cashfree', 'attempt' => CASHFREE_ATTEMPT, 'order_status' => 'PAID', 'txStatus' => 'SUCCESS', 'order_amount' => '708.50']));
        $this->call('POST', route('pay.webhook', 'cashfree'), [], [], [], cashfreeHookHeaders($body), $body)->assertOk();

        expect(Payment::query()->count())->toBe(0)
            ->and($this->payment->fresh()->status)->toBe($expected);
    })->with([
        'active' => [cashfreeOrder('ACTIVE'), OnlinePaymentStatus::Pending],
        'expired' => [cashfreeOrder('EXPIRED'), OnlinePaymentStatus::Failed],
    ]);

    it('marks the attempt for attention when Cashfree took a lower amount', function () {
        fakeCashfree(cashfreeOrder('PAID', 1));

        $this->get(route('pay.return', ['cashfree', 'attempt' => CASHFREE_ATTEMPT]));

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::NeedsAttention)->and(Payment::query()->count())->toBe(0);
    });

    it('records nothing for a currency other than INR', function () {
        fakeCashfree(cashfreeOrder('PAID', 708.5, 'USD'));

        $this->get(route('pay.return', ['cashfree', 'attempt' => CASHFREE_ATTEMPT]));

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::NeedsAttention)->and(Payment::query()->count())->toBe(0);
    });

    it('ignores a webhook with a wrong signature', function () {
        fakeCashfree(cashfreeOrder('PAID'));
        $body = cashfreeHookBody();

        $this->call('POST', route('pay.webhook', 'cashfree'), [], [], [], cashfreeHookHeaders($body, base64_encode('nope')), $body)->assertNotFound();

        Http::assertNothingSent();
        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending);
    });

    it('keeps keys, tokens and signatures out of the stored answer', function () {
        fakeCashfree(cashfreeOrder('ACTIVE'));

        $this->get(route('pay.return', ['cashfree', 'attempt' => CASHFREE_ATTEMPT]));

        $stored = (string) json_encode($this->payment->fresh()->gateway_response);
        expect($stored)->toContain('2149460581')
            ->not->toContain('session_SECRETTOKEN')->not->toContain(CASHFREE_SECRET)->not->toContain(CASHFREE_ID)
            ->not->toContain('asha@example.com')->not->toContain('9876543210');
    });
});

it('renders the Cashfree init on the go page and nothing for an unknown init', function () {
    $page = fn (string $init): string => view('pay.go', [...PublicPage::data(), 'start' => GatewayStart::script('https://sdk.cashfree.com/js/v3/cashfree.js', ['init' => $init, 'mode' => 'sandbox', 'session' => 'sess"</script><script>alert(1)//'])])->render();

    $html = $page('cashfree');

    expect($html)->toContain('<script src="https://sdk.cashfree.com/js/v3/cashfree.js"')
        ->toContain("Cashfree({ mode: 'sandbox' }).checkout({ paymentSessionId: 'sess\u0022\u003C\/script\u003E\u003Cscript\u003Ealert(1)\/\/', redirectTarget: '_self' })")
        ->not->toContain('<script>alert(1)');
    expect(strpos($html, 'id="pay-go-fallback"'))->toBeLessThan(strpos($html, "getElementById('pay-go-fallback')"));

    foreach (['evil', '../x', 'Cashfree', "cashfree\n", 'cash free', ''] as $init) {
        expect($page($init))->not->toContain('checkout(')->not->toContain('Cashfree({');
    }
});
