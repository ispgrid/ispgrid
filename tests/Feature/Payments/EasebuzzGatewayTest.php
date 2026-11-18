<?php

use App\Actions\Billing\IssueInvoice;
use App\Contracts\NeedsCustomerDetails;
use App\Contracts\UntestedGateway;
use App\Enums\InvoiceKind;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\GatewayUnavailable;
use App\Models\Account;
use App\Models\GatewaySetting;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Payments\Easebuzz\EasebuzzGateway;
use App\Payments\GatewayStatus;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const EASEBUZZ_KEY = 'easebuzzkeyfortests';
const EASEBUZZ_SALT = 'easebuzz-salt-value-5c1e';
const EASEBUZZ_ATTEMPT = '01JQUICKPAYTESTORDER000004';
const EASEBUZZ_ACCESS = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

beforeEach(function () {
    $this->payment = OnlinePayment::factory()->create([
        'public_id' => EASEBUZZ_ATTEMPT, 'gateway' => 'easebuzz', 'gateway_order_id' => null, 'amount' => '708.50', 'currency' => 'INR',
        'account_id' => Account::factory()->create(['fullname' => 'Asha Rao', 'mobile' => '9876543210', 'email' => 'asha@example.com']),
    ]);
});

/**
 * @param  array<string, string>  $settings
 */
function easebuzz(array $settings = []): EasebuzzGateway
{
    return new EasebuzzGateway(easebuzzSettings($settings));
}

/**
 * @param  array<string, string>  $settings
 * @return array<string, string>
 */
function easebuzzSettings(array $settings = []): array
{
    return [...['environment' => 'test', 'merchant_key' => EASEBUZZ_KEY, 'salt' => EASEBUZZ_SALT, 'host' => '', 'dashboard_host' => ''], ...$settings];
}

/**
 * @return array<string, mixed>
 */
function easebuzzEntry(string $status, string $amount = '708.50', string $txn = EASEBUZZ_ATTEMPT): array
{
    return ['txnid' => $txn, 'status' => $status, 'amount' => $amount, 'easepayid' => 'E2611180001', 'mode' => 'UPI', 'bank_ref_num' => 'BR123',
        'email' => 'asha@example.com', 'phone' => '9876543210', 'cardnum' => '4242XXXXXXXX4242', 'error_Message' => 'bank said ok'];
}

/**
 * @param  array<string, mixed>  $body
 */
function easebuzzFakeBody(array $body): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(['*/transaction/v2/retrieve' => Http::response($body)]);
}

/**
 * @param  array<string, mixed>  $entry
 */
function easebuzzFake(array $entry): void
{
    easebuzzFakeBody(['status' => true, 'data' => $entry]);
}

function easebuzzFakeInitiate(mixed $response = null): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(['*/payment/initiateLink' => $response ?? Http::response(['status' => 1, 'data' => EASEBUZZ_ACCESS])]);
}

/**
 * A post as Easebuzz sends it back, with its reverse hash.
 *
 * @param  array<string, string>  $fields
 * @return array<string, string>
 */
function easebuzzSigned(array $fields = [], ?string $salt = null): array
{
    $f = [...['status' => 'success', 'key' => EASEBUZZ_KEY, 'txnid' => EASEBUZZ_ATTEMPT, 'amount' => '708.50', 'productinfo' => 'Internet bill',
        'firstname' => 'Asha Rao', 'email' => 'asha@example.com', 'udf1' => '', 'udf2' => '', 'udf3' => '', 'udf4' => '', 'udf5' => '', 'udf6' => '', 'udf7' => '',
        'udf8' => '', 'udf9' => '', 'udf10' => ''], ...$fields];
    $f['hash'] = hash('sha512', ($salt ?? EASEBUZZ_SALT).'|'.$f['status'].'|'.$f['udf10'].'|'.$f['udf9'].'|'.$f['udf8'].'|'.$f['udf7'].'|'.$f['udf6'].'|'.$f['udf5'].'|'
        .$f['udf4'].'|'.$f['udf3'].'|'.$f['udf2'].'|'.$f['udf1'].'|'.$f['email'].'|'.$f['firstname'].'|'.$f['productinfo'].'|'.$f['amount'].'|'.$f['txnid'].'|'.$f['key']);

    return $f;
}

it('is marked untested and needs the customer\'s mobile and email', function () {
    expect(easebuzz())->toBeInstanceOf(UntestedGateway::class)->toBeInstanceOf(NeedsCustomerDetails::class)
        ->and(EasebuzzGateway::requiredDetails())->toBe(['phone', 'email'])
        ->and(EasebuzzGateway::key())->toBe('easebuzz')
        ->and(EasebuzzGateway::currencies())->toBe(['INR']);
});

it('starts a payment with the attempt\'s amount, currency, order id and return url', function () {
    easebuzzFakeInitiate();
    $return = 'https://isp.example/pay/return/easebuzz?attempt='.EASEBUZZ_ATTEMPT;

    $start = easebuzz()->start($this->payment, $return);

    $hash = hash('sha512', EASEBUZZ_KEY.'|'.EASEBUZZ_ATTEMPT.'|708.50|Internet bill|Asha Rao|asha@example.com|||||||||||'.EASEBUZZ_SALT);
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://testpay.easebuzz.in/payment/initiateLink'
        && $request->method() === 'POST'
        && $request->data() === ['key' => EASEBUZZ_KEY, 'txnid' => EASEBUZZ_ATTEMPT, 'amount' => '708.50', 'productinfo' => 'Internet bill', 'firstname' => 'Asha Rao',
            'phone' => '9876543210', 'email' => 'asha@example.com', 'surl' => $return, 'furl' => $return, 'hash' => $hash]);

    expect($start->kind)->toBe('redirect')
        ->and($start->url)->toBe('https://testpay.easebuzz.in/pay/'.EASEBUZZ_ACCESS)
        ->and($start->orderId)->toBe(EASEBUZZ_ATTEMPT)
        ->and($start->url)->not->toContain(EASEBUZZ_SALT);
});

it('uses the live and overridden hosts and a letters only first name', function () {
    easebuzzFakeInitiate();
    $this->payment->account->update(['fullname' => 'R2-D2 O\'Neil, Jr.']);

    $live = easebuzz(['environment' => 'live'])->start($this->payment->fresh(), 'https://isp.example/r');
    $custom = easebuzz(['host' => 'https://pay.example.net/'])->start($this->payment->fresh(), 'https://isp.example/r');

    expect($live->url)->toBe('https://pay.easebuzz.in/pay/'.EASEBUZZ_ACCESS)
        ->and($custom->url)->toBe('https://pay.example.net/pay/'.EASEBUZZ_ACCESS);
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://pay.easebuzz.in/payment/initiateLink'
        && $request['firstname'] === 'R D O Neil Jr'
        && $request['hash'] === hash('sha512', EASEBUZZ_KEY.'|'.EASEBUZZ_ATTEMPT.'|708.50|Internet bill|R D O Neil Jr|asha@example.com|||||||||||'.EASEBUZZ_SALT));
});

it('keeps combining marks in the first name', function () {
    easebuzzFakeInitiate();
    $this->payment->account->update(['fullname' => 'राम']);

    easebuzz()->start($this->payment->fresh(), 'https://isp.example/r');

    Http::assertSent(fn (ClientRequest $request): bool => $request['firstname'] === 'राम');
});

it('refuses a start without an email or a mobile', function () {
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    $bare = Account::factory()->create(['mobile' => null, 'email' => null]);
    $attempt = OnlinePayment::factory()->create(['gateway' => 'easebuzz', 'amount' => '10.00', 'currency' => 'INR', 'account_id' => $bare]);

    expect(fn () => easebuzz()->start($attempt, 'https://isp.example/r'))->toThrow(GatewayUnavailable::class);
    Http::assertNothingSent();
});

it('refuses a start when Easebuzz does not return an access key, without showing its answer', function (callable $response) {
    easebuzzFakeInitiate($response());

    try {
        easebuzz()->start($this->payment, 'https://isp.example/r');
        $this->fail('expected GatewayUnavailable');
    } catch (GatewayUnavailable $exception) {
        expect($exception->getMessage())->not->toContain('secret-detail');
    }
})->with([
    'status 0' => [fn () => Http::response(['status' => 0, 'data' => 'secret-detail'])],
    'no data' => [fn () => Http::response(['status' => 1])],
    'array data' => [fn () => Http::response(['status' => 1, 'data' => ['secret-detail']])],
    'array status' => [fn () => Http::response(['status' => [1], 'data' => EASEBUZZ_ACCESS])],
    'server error' => [fn () => Http::response('secret-detail', 500)],
]);

it('reports paid only for the final success state with the amount and currency', function () {
    easebuzzFake(easebuzzEntry('success', '100.00'));

    $result = easebuzz()->status($this->payment);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://testdashboard.easebuzz.in/transaction/v2/retrieve'
        && $request->method() === 'POST'
        && $request->data() === ['txnid' => EASEBUZZ_ATTEMPT, 'key' => EASEBUZZ_KEY, 'hash' => hash('sha512', EASEBUZZ_KEY.'|'.EASEBUZZ_ATTEMPT.'|'.EASEBUZZ_SALT)]);

    expect($result->status)->toBe(GatewayStatus::Paid)
        ->and($result->amount)->toBe('100.00')
        ->and($result->currency)->toBe('INR')
        ->and($result->txnId)->toBe('E2611180001')
        ->and($result->providerStatus)->toBe('success');
});

it('asks the live dashboard host, reads a list answer, lets a success win and writes two decimals', function () {
    easebuzzFakeBody(['status' => true, 'data' => [easebuzzEntry('failure'), easebuzzEntry('success', '708.5'), easebuzzEntry('userCancelled')]]);

    $result = easebuzz(['environment' => 'live'])->status($this->payment);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://dashboard.easebuzz.in/transaction/v2/retrieve');
    expect($result->status)->toBe(GatewayStatus::Paid)->and($result->amount)->toBe('708.50');
});

it('accepts a numeric amount and an array top level status without failing', function () {
    easebuzzFake([...easebuzzEntry('success'), 'amount' => 708.5]);
    expect(easebuzz()->status($this->payment)->amount)->toBe('708.50');

    easebuzzFakeBody(['status' => ['1'], 'data' => easebuzzEntry('success')]);
    expect(easebuzz()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
});

it('reports pending for anything that is not a success, a failure too because the customer may retry', function (array $entry) {
    easebuzzFake($entry);

    expect(easebuzz()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
})->with([
    'failure' => [easebuzzEntry('failure')],
    'user cancelled' => [easebuzzEntry('userCancelled')],
    'dropped' => [easebuzzEntry('dropped')],
    'bounced' => [easebuzzEntry('bounced')],
    'pending' => [easebuzzEntry('pending')],
    'array status' => [[...easebuzzEntry('success'), 'status' => ['x']]],
    'array amount' => [[...easebuzzEntry('success'), 'amount' => ['1']]],
    'no status key' => [['txnid' => EASEBUZZ_ATTEMPT]],
    'success without an amount' => [['txnid' => EASEBUZZ_ATTEMPT, 'status' => 'success']],
    'success with a junk amount' => [easebuzzEntry('success', 'abc')],
    'another txnid' => [easebuzzEntry('success', txn: 'OTHERTXN')],
    'no txnid' => [['status' => 'success', 'amount' => '708.50']],
]);

it('reports pending when the answer says it failed or has no transaction', function (array $body) {
    easebuzzFakeBody($body);

    expect(easebuzz()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
})->with([
    'status false' => [['status' => false, 'data' => easebuzzEntry('success')]],
    'status 0' => [['status' => 0, 'data' => easebuzzEntry('success')]],
    'data is text' => [['status' => true, 'data' => 'Transaction not found']],
    'no data' => [['status' => true]],
    'empty list' => [['status' => true, 'data' => []]],
]);

it('reports pending when the provider cannot be reached or answers an error', function (callable $fake) {
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    $fake();

    expect(easebuzz()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
})->with([
    'server error' => [fn () => Http::fake(['*' => Http::response('oops', 500)])],
    'bad request' => [fn () => Http::fake(['*' => Http::response(['status' => false, 'msg' => 'Invalid hash'], 400)])],
    'not json' => [fn () => Http::fake(['*' => Http::response('<html></html>', 200)])],
    'unreachable' => [fn () => Http::fake(['*' => Http::failedConnection()])],
]);

it('accepts a genuine return and webhook and names the attempt', function () {
    $cancelled = Request::create('/pay/return/easebuzz', 'GET', ['attempt' => EASEBUZZ_ATTEMPT]);

    expect(easebuzz()->verify(Request::create('/pay/return/easebuzz?attempt='.EASEBUZZ_ATTEMPT, 'POST', easebuzzSigned())))->toBe(EASEBUZZ_ATTEMPT)
        ->and(easebuzz()->verify(Request::create('/pay/webhook/easebuzz', 'POST', easebuzzSigned(['status' => 'failure']))))->toBe(EASEBUZZ_ATTEMPT)
        ->and(easebuzz()->verify(Request::create('/pay/webhook/easebuzz', 'POST', easebuzzSigned(['udf3' => 'x', 'firstname' => 'Asha']))))->toBe(EASEBUZZ_ATTEMPT)
        ->and(easebuzz()->verify($cancelled))->toBe(EASEBUZZ_ATTEMPT);
});

it('rejects a bad signature, an unknown attempt and another gateway\'s attempt', function () {
    OnlinePayment::factory()->create(['public_id' => 'OTHERGATEWAYATTEMPT00002', 'gateway' => 'fake', 'gateway_order_id' => 'OTHERGWTXN']);
    $post = fn (array $fields, string $path = '/pay/return/easebuzz'): Request => Request::create($path, 'POST', $fields);
    $good = easebuzzSigned();
    $noHash = array_diff_key($good, ['hash' => 1]);

    expect(easebuzz()->verify($post([...$good, 'hash' => 'deadbeef'])))->toBeNull()
        ->and(easebuzz()->verify($post($noHash)))->toBeNull()
        ->and(easebuzz()->verify($post([...$good, 'hash' => ''])))->toBeNull()
        ->and(easebuzz()->verify($post([...$good, 'amount' => '1.00'])))->toBeNull()
        ->and(easebuzz()->verify($post([...$good, 'status' => 'failure'])))->toBeNull()
        ->and(easebuzz()->verify($post(easebuzzSigned(salt: 'another-salt'))))->toBeNull()
        ->and(easebuzz()->verify($post([...$good, 'txnid' => ['x']])))->toBeNull()
        ->and(easebuzz()->verify($post(easebuzzSigned(['txnid' => 'UNKNOWNTXN']))))->toBeNull()
        ->and(easebuzz()->verify($post(easebuzzSigned(['txnid' => 'OTHERGATEWAYATTEMPT00002']))))->toBeNull()
        ->and(easebuzz()->verify($post(easebuzzSigned(['key' => 'someoneelseskey']))))->toBeNull()
        ->and(easebuzz(['merchant_key' => 'someoneelseskey'])->verify($post($good)))->toBeNull()
        ->and(easebuzz(['salt' => ''])->verify($post(easebuzzSigned(salt: ''))))->toBeNull()
        ->and(easebuzz()->verify($post([...$good, 'hash' => ''], '/pay/webhook/easebuzz')))->toBeNull()
        ->and(easebuzz()->verify(Request::create('/pay/return/easebuzz', 'GET', ['attempt' => 'NOSUCHATTEMPT'])))->toBeNull()
        ->and(easebuzz()->verify(Request::create('/pay/return/easebuzz', 'GET', ['attempt' => 'OTHERGATEWAYATTEMPT00002'])))->toBeNull()
        ->and(easebuzz()->verify(Request::create('/pay/return/easebuzz', 'GET')))->toBeNull()
        ->and(easebuzz()->verify(Request::create('/pay/webhook/easebuzz?attempt='.EASEBUZZ_ATTEMPT, 'POST')))->toBeNull();
});

describe('through the routes', function () {
    beforeEach(function () {
        GatewaySetting::query()->updateOrCreate(['gateway' => 'easebuzz'], ['enabled' => true, 'settings' => easebuzzSettings()]);
    });

    it('records nothing when a genuine-looking success return meets a status that is not paid', function (array $entry, OnlinePaymentStatus $expected) {
        easebuzzFake($entry);

        $this->post(route('pay.return', ['easebuzz', 'attempt' => EASEBUZZ_ATTEMPT]), easebuzzSigned());
        $this->post(route('pay.webhook', 'easebuzz'), easebuzzSigned())->assertOk();

        expect(Payment::query()->count())->toBe(0)->and($this->payment->fresh()->status)->toBe($expected);
    })->with([
        'pending' => [easebuzzEntry('pending'), OnlinePaymentStatus::Pending],
        'failure' => [easebuzzEntry('failure'), OnlinePaymentStatus::Pending],
        'lower amount' => [easebuzzEntry('success', '1.00'), OnlinePaymentStatus::NeedsAttention],
        'higher amount' => [easebuzzEntry('success', '9999.00'), OnlinePaymentStatus::NeedsAttention],
    ]);

    it('keeps a failed try pending and still records the payment when the customer retries and pays', function () {
        turnQuickPayOn('7');
        app(IssueInvoice::class)->handle($this->payment->account, billingLines('100'), false, InvoiceKind::Manual, now());
        $this->payment->update(['amount' => '118.00', 'dues_amount' => '118.00']);

        easebuzzFake(easebuzzEntry('failure', '118.00'));
        $this->post(route('pay.webhook', 'easebuzz'), easebuzzSigned(['status' => 'failure', 'amount' => '118.00']))->assertOk();

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending)->and(Payment::query()->count())->toBe(0);

        easebuzzFake(easebuzzEntry('success', '118.00'));
        $this->post(route('pay.webhook', 'easebuzz'), easebuzzSigned(['amount' => '118.00']))->assertOk();

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Paid)->and(Payment::query()->count())->toBe(1);
    });

    it('records nothing for a posted success whose amount was changed by the browser', function () {
        easebuzzFake(easebuzzEntry('pending'));

        $this->post(route('pay.return', ['easebuzz', 'attempt' => EASEBUZZ_ATTEMPT]), easebuzzSigned(['amount' => '1.00', 'status' => 'success']));

        expect(Payment::query()->count())->toBe(0)->and($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending);
    });

    it('does not mark anything on a cancelled return, only re-checks', function () {
        easebuzzFake(easebuzzEntry('pending'));

        $this->get(route('pay.return', ['easebuzz', 'attempt' => EASEBUZZ_ATTEMPT]));

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending)->and(Payment::query()->count())->toBe(0);
    });

    it('ignores a webhook with a wrong hash', function () {
        easebuzzFake(easebuzzEntry('success'));

        $this->post(route('pay.webhook', 'easebuzz'), [...easebuzzSigned(), 'hash' => 'nope'])->assertNotFound();

        Http::assertNothingSent();
        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending);
    });

    it('keeps keys, tokens and signatures out of the stored answer', function () {
        easebuzzFake(easebuzzEntry('pending'));

        $this->post(route('pay.return', ['easebuzz', 'attempt' => EASEBUZZ_ATTEMPT]), easebuzzSigned());

        $stored = (string) json_encode($this->payment->fresh()->gateway_response);
        expect($stored)->toContain('E2611180001')
            ->not->toContain(EASEBUZZ_SALT)->not->toContain(EASEBUZZ_KEY)->not->toContain(easebuzzSigned()['hash'])
            ->not->toContain('4242')->not->toContain('9876543210')->not->toContain('asha@example.com')->not->toContain('bank said ok');
    });
});
