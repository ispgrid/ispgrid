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
use App\Payments\Payu\PayuGateway;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const PAYU_KEY = 'payukeyfortests01';
const PAYU_SALT = 'payu-salt-value-7d3f';
const PAYU_ATTEMPT = '01JQUICKPAYTESTORDER000003';
const PAYU_TXN = '1JQUICKPAYTESTORDER000003';

beforeEach(function () {
    $this->payment = OnlinePayment::factory()->create([
        'public_id' => PAYU_ATTEMPT, 'gateway' => 'payu', 'gateway_order_id' => PAYU_TXN, 'amount' => '708.50', 'currency' => 'INR',
        'account_id' => Account::factory()->create(['fullname' => 'Asha Rao', 'mobile' => '9876543210', 'email' => 'asha@example.com']),
    ]);
});

/**
 * @param  array<string, string>  $settings
 */
function payu(array $settings = []): PayuGateway
{
    return new PayuGateway(payuSettings($settings));
}

/**
 * @param  array<string, string>  $settings
 * @return array<string, string>
 */
function payuSettings(array $settings = []): array
{
    return [...['environment' => 'test', 'merchant_key' => PAYU_KEY, 'merchant_salt' => PAYU_SALT, 'host' => '', 'info_host' => ''], ...$settings];
}

/**
 * @return array<string, mixed>
 */
function payuEntry(string $status, string $amount = '708.50', string $txn = PAYU_TXN): array
{
    return ['mihpayid' => '403993715521', 'txnid' => $txn, 'status' => $status, 'amt' => $amount, 'mode' => 'UPI', 'unmappedstatus' => 'captured',
        'email' => 'asha@example.com', 'phone' => '9876543210', 'card_no' => '4242424242424242', 'name_on_card' => 'Asha', 'field9' => 'bank said ok'];
}

/**
 * @param  array<string, mixed>  $entry
 */
function payuFake(array $entry, int $top = 1): void
{
    Http::fake(['*/merchant/postservice*' => Http::response(['status' => $top, 'msg' => '1 out of 1 Transactions Fetched Successfully', 'transaction_details' => [PAYU_TXN => $entry]])]);
}

/**
 * A post as PayU sends it back, with its reverse hash.
 *
 * @param  array<string, string>  $fields
 * @return array<string, string>
 */
function payuSigned(array $fields = [], ?string $salt = null): array
{
    $f = [...['status' => 'success', 'key' => PAYU_KEY, 'txnid' => PAYU_TXN, 'amount' => '708.50', 'productinfo' => 'Internet bill', 'firstname' => 'Asha Rao',
        'email' => 'asha@example.com', 'udf1' => PAYU_ATTEMPT, 'udf2' => '', 'udf3' => '', 'udf4' => '', 'udf5' => ''], ...$fields];
    $charges = ($f['additionalCharges'] ?? '') !== '' ? $f['additionalCharges'].'|' : '';
    $f['hash'] = hash('sha512', $charges.($salt ?? PAYU_SALT).'|'.$f['status'].'||||||'.$f['udf5'].'|'.$f['udf4'].'|'.$f['udf3'].'|'.$f['udf2'].'|'.$f['udf1'].'|'
        .$f['email'].'|'.$f['firstname'].'|'.$f['productinfo'].'|'.$f['amount'].'|'.$f['txnid'].'|'.$f['key']);

    return $f;
}

it('is marked untested and needs the customer\'s mobile and email', function () {
    expect(payu())->toBeInstanceOf(UntestedGateway::class)->toBeInstanceOf(NeedsCustomerDetails::class)
        ->and(PayuGateway::requiredDetails())->toBe(['phone', 'email'])
        ->and(PayuGateway::key())->toBe('payu')
        ->and(PayuGateway::currencies())->toBe(['INR']);
});

it('starts a payment with the attempt\'s amount, currency, order id and return url', function () {
    $return = 'https://isp.example/pay/return/payu?attempt='.PAYU_ATTEMPT;
    $start = payu()->start($this->payment, $return);

    $expected = hash('sha512', PAYU_KEY.'|'.PAYU_TXN.'|708.50|Internet bill|Asha Rao|asha@example.com|'.PAYU_ATTEMPT.'||||||||||'.PAYU_SALT);

    expect($start->kind)->toBe('form')
        ->and($start->url)->toBe('https://test.payu.in/_payment')
        ->and($start->orderId)->toBe(PAYU_TXN)
        ->and($start->fields)->toBe([
            'key' => PAYU_KEY, 'txnid' => PAYU_TXN, 'amount' => '708.50', 'productinfo' => 'Internet bill', 'firstname' => 'Asha Rao',
            'email' => 'asha@example.com', 'phone' => '9876543210', 'surl' => $return, 'furl' => $return, 'udf1' => PAYU_ATTEMPT, 'hash' => $expected,
        ])
        ->and(strlen($start->orderId))->toBeLessThanOrEqual(25)
        ->and(json_encode($start->fields))->not->toContain(PAYU_SALT);
});

it('uses the live and overridden hosts and a letters only first name', function () {
    $this->payment->account->update(['fullname' => 'R2-D2 O\'Neil, Jr.']);

    $live = payu(['environment' => 'live'])->start($this->payment->fresh(), 'https://isp.example/r');
    $custom = payu(['host' => 'https://pay.example.net/'])->start($this->payment->fresh(), 'https://isp.example/r');

    expect($live->url)->toBe('https://secure.payu.in/_payment')
        ->and($custom->url)->toBe('https://pay.example.net/_payment')
        ->and($live->fields['firstname'])->toBe('R D O Neil Jr')
        ->and($live->fields['hash'])->toBe(hash('sha512', PAYU_KEY.'|'.PAYU_TXN.'|708.50|Internet bill|R D O Neil Jr|asha@example.com|'.PAYU_ATTEMPT.'||||||||||'.PAYU_SALT));
});

it('refuses a start without an email or a mobile', function () {
    $bare = Account::factory()->create(['mobile' => null, 'email' => null]);
    $attempt = OnlinePayment::factory()->create(['gateway' => 'payu', 'amount' => '10.00', 'currency' => 'INR', 'account_id' => $bare]);

    expect(fn () => payu()->start($attempt, 'https://isp.example/r'))->toThrow(GatewayUnavailable::class);
});

it('reports paid only for the final success state with the amount and currency', function () {
    payuFake(payuEntry('success', '100.00'));

    $result = payu()->status($this->payment);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://test.payu.in/merchant/postservice?form=2'
        && $request->method() === 'POST'
        && $request->data() === ['key' => PAYU_KEY, 'command' => 'verify_payment', 'var1' => PAYU_TXN, 'hash' => hash('sha512', PAYU_KEY.'|verify_payment|'.PAYU_TXN.'|'.PAYU_SALT)]);

    expect($result->status)->toBe(GatewayStatus::Paid)
        ->and($result->amount)->toBe('100.00')
        ->and($result->currency)->toBe('INR')
        ->and($result->txnId)->toBe('403993715521')
        ->and($result->providerStatus)->toBe('success');
});

it('asks the live status host and writes the amount with two decimals', function () {
    payuFake(payuEntry('success', '708.5'));

    expect(payu(['environment' => 'live'])->status($this->payment)->amount)->toBe('708.50');
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://info.payu.in/merchant/postservice?form=2');
});

it('reports failed for a final failure state and pending for anything else', function (array $entry, int $top, GatewayStatus $expected) {
    payuFake($entry, $top);

    expect(payu()->status($this->payment)->status)->toBe($expected);
})->with([
    'failure' => [payuEntry('failure'), 1, GatewayStatus::Failed],
    'pending' => [payuEntry('pending'), 1, GatewayStatus::Pending],
    'not found' => [['mihpayid' => 'Not Found', 'status' => 'Not Found'], 1, GatewayStatus::Pending],
    'top level 0' => [payuEntry('success'), 0, GatewayStatus::Pending],
    'no status key' => [['mihpayid' => '1', 'txnid' => PAYU_TXN], 1, GatewayStatus::Pending],
    'success without an amount' => [['mihpayid' => '1', 'txnid' => PAYU_TXN, 'status' => 'success'], 1, GatewayStatus::Pending],
    'success with a junk amount' => [payuEntry('success', 'abc'), 1, GatewayStatus::Pending],
    'another txnid' => [payuEntry('success', txn: 'OTHERTXN'), 1, GatewayStatus::Pending],
]);

it('reports pending for an answer without the transaction or without an order id', function () {
    Http::fake(['*' => Http::response(['status' => 1, 'transaction_details' => 'nope'])]);
    expect(payu()->status($this->payment)->status)->toBe(GatewayStatus::Pending);

    Http::fake(['*' => Http::response(['status' => 1])]);
    expect(payu()->status($this->payment)->status)->toBe(GatewayStatus::Pending);

    Http::fake();
    $this->payment->forceFill(['gateway_order_id' => null])->save();
    expect(payu()->status($this->payment->fresh())->status)->toBe(GatewayStatus::Pending);
    Http::assertNothingSent();
});

it('reports pending when the provider cannot be reached or answers an error', function (callable $fake) {
    $fake();

    expect(payu()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
})->with([
    'server error' => [fn () => Http::fake(['*' => Http::response('oops', 500)])],
    'bad request' => [fn () => Http::fake(['*' => Http::response(['status' => 0, 'msg' => 'Invalid hash'], 400)])],
    'not json' => [fn () => Http::fake(['*' => Http::response('<html></html>', 200)])],
    'unreachable' => [fn () => Http::fake(['*' => Http::failedConnection()])],
]);

it('accepts a genuine return and webhook and names the attempt', function () {
    $cancelled = Request::create('/pay/return/payu', 'GET', ['attempt' => PAYU_ATTEMPT]);

    expect(payu()->verify(Request::create('/pay/return/payu?attempt='.PAYU_ATTEMPT, 'POST', payuSigned())))->toBe(PAYU_ATTEMPT)
        ->and(payu()->verify(Request::create('/pay/webhook/payu', 'POST', payuSigned(['status' => 'failure']))))->toBe(PAYU_ATTEMPT)
        ->and(payu()->verify(Request::create('/pay/webhook/payu', 'POST', [...payuSigned(['additionalCharges' => '12.00']), 'additionalCharges' => '12.00'])))->toBe(PAYU_ATTEMPT)
        ->and(payu()->verify($cancelled))->toBe(PAYU_ATTEMPT);
});

it('rejects a bad signature, an unknown attempt and another gateway\'s attempt', function () {
    OnlinePayment::factory()->create(['public_id' => 'OTHERGATEWAYATTEMPT00002', 'gateway' => 'fake', 'gateway_order_id' => 'OTHERGWTXN']);
    $post = fn (array $fields, string $path = '/pay/return/payu'): Request => Request::create($path, 'POST', $fields);
    $good = payuSigned();
    $noHash = array_diff_key($good, ['hash' => 1]);

    expect(payu()->verify($post([...$good, 'hash' => 'deadbeef'])))->toBeNull()
        ->and(payu()->verify($post($noHash)))->toBeNull()
        ->and(payu()->verify($post([...$good, 'hash' => ''])))->toBeNull()
        ->and(payu()->verify($post([...$good, 'amount' => '1.00'])))->toBeNull()
        ->and(payu()->verify($post([...$good, 'status' => 'failure'])))->toBeNull()
        ->and(payu()->verify($post([...$good, 'additionalCharges' => '12.00'])))->toBeNull()
        ->and(payu()->verify($post(payuSigned(salt: 'another-salt'))))->toBeNull()
        ->and(payu()->verify($post([...$good, 'txnid' => ['x']])))->toBeNull()
        ->and(payu()->verify($post(payuSigned(['txnid' => 'UNKNOWNTXN']))))->toBeNull()
        ->and(payu()->verify($post(payuSigned(['txnid' => 'OTHERGWTXN']))))->toBeNull()
        ->and(payu()->verify($post(payuSigned(['txnid' => 'OTHERGATEWAYATTEMPT00002']))))->toBeNull()
        ->and(payu()->verify($post(payuSigned(['key' => 'someoneelseskey']))))->toBeNull()
        ->and(payu(['merchant_key' => 'someoneelseskey'])->verify($post($good)))->toBeNull()
        ->and(payu(['merchant_salt' => ''])->verify($post(payuSigned(salt: ''))))->toBeNull()
        ->and(payu()->verify($post([...$good, 'hash' => ''], '/pay/webhook/payu')))->toBeNull()
        ->and(payu()->verify(Request::create('/pay/return/payu', 'GET', ['attempt' => 'NOSUCHATTEMPT'])))->toBeNull()
        ->and(payu()->verify(Request::create('/pay/return/payu', 'GET', ['attempt' => 'OTHERGATEWAYATTEMPT00002'])))->toBeNull()
        ->and(payu()->verify(Request::create('/pay/return/payu', 'GET')))->toBeNull()
        ->and(payu()->verify(Request::create('/pay/webhook/payu?attempt='.PAYU_ATTEMPT, 'POST')))->toBeNull();
});

describe('through the routes', function () {
    beforeEach(function () {
        GatewaySetting::query()->updateOrCreate(['gateway' => 'payu'], ['enabled' => true, 'settings' => payuSettings()]);
    });

    it('records nothing when a genuine-looking success return meets a status that is not paid', function (array $entry, OnlinePaymentStatus $expected) {
        payuFake($entry);

        $this->post(route('pay.return', ['payu', 'attempt' => PAYU_ATTEMPT]), payuSigned());
        $this->post(route('pay.webhook', 'payu'), payuSigned())->assertOk();

        expect(Payment::query()->count())->toBe(0)->and($this->payment->fresh()->status)->toBe($expected);
    })->with([
        'pending' => [payuEntry('pending'), OnlinePaymentStatus::Pending],
        'not found' => [['mihpayid' => 'Not Found', 'status' => 'Not Found'], OnlinePaymentStatus::Pending],
        'lower amount' => [payuEntry('success', '1.00'), OnlinePaymentStatus::NeedsAttention],
        'higher amount' => [payuEntry('success', '9999.00'), OnlinePaymentStatus::NeedsAttention],
    ]);

    it('records nothing for a posted success whose amount was changed by the browser', function () {
        payuFake(payuEntry('pending'));

        $this->post(route('pay.return', ['payu', 'attempt' => PAYU_ATTEMPT]), payuSigned(['amount' => '1.00', 'status' => 'success']));

        expect(Payment::query()->count())->toBe(0)->and($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending);
    });

    it('does not mark anything on a cancelled return, only re-checks', function () {
        payuFake(payuEntry('pending'));

        $this->get(route('pay.return', ['payu', 'attempt' => PAYU_ATTEMPT]));

        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending)->and(Payment::query()->count())->toBe(0);
    });

    it('ignores a webhook with a wrong hash', function () {
        payuFake(payuEntry('success'));

        $this->post(route('pay.webhook', 'payu'), [...payuSigned(), 'hash' => 'nope'])->assertNotFound();

        Http::assertNothingSent();
        expect($this->payment->fresh()->status)->toBe(OnlinePaymentStatus::Pending);
    });

    it('keeps keys, tokens and signatures out of the stored answer', function () {
        payuFake(payuEntry('pending'));

        $this->post(route('pay.return', ['payu', 'attempt' => PAYU_ATTEMPT]), payuSigned());

        $stored = (string) json_encode($this->payment->fresh()->gateway_response);
        expect($stored)->toContain('403993715521')
            ->not->toContain(PAYU_SALT)->not->toContain(PAYU_KEY)->not->toContain(payuSigned()['hash'])
            ->not->toContain('4242')->not->toContain('9876543210')->not->toContain('asha@example.com')->not->toContain('bank said ok');
    });
});
