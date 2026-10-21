<?php

use App\Exceptions\GatewayUnavailable;
use App\Models\OnlinePayment;
use App\Payments\GatewayStatus;
use App\Payments\Paytm\PaytmChecksum;
use App\Payments\Paytm\PaytmGateway;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const PAYTM_KEY = 'abcd1234ABCD!@#$';

beforeEach(function () {
    $this->payment = OnlinePayment::factory()->create(['public_id' => '01JQUICKPAYTESTORDER000001', 'amount' => '708.5', 'currency' => 'INR']);
});

function paytm(array $settings = []): PaytmGateway
{
    return new PaytmGateway([...['environment' => 'test', 'mid' => 'TESTMID', 'merchant_key' => PAYTM_KEY, 'website_name' => '', 'host' => ''], ...$settings]);
}

it('starts a transaction on the test host with a signed body and posts the customer to the payment page', function () {
    Http::fake(['*' => Http::response(['head' => [], 'body' => ['resultInfo' => ['resultStatus' => 'S', 'resultCode' => '0000'], 'txnToken' => 'TOKEN123']])]);

    $start = paytm()->start($this->payment, 'https://isp.example/pay/return/paytm');

    Http::assertSent(function (ClientRequest $request): bool {
        $raw = $request->body();
        preg_match('/^\{"body":(\{.*\}),"head":\{"signature":"([^"]+)"\}\}$/', $raw, $parts);
        $body = json_decode($parts[1], true);

        return $request->url() === 'https://securestage.paytmpayments.com/theia/api/v1/initiateTransaction?mid=TESTMID&orderId=01JQUICKPAYTESTORDER000001'
            && PaytmChecksum::verify($parts[1], PAYTM_KEY, $parts[2])
            && $body['requestType'] === 'Payment' && $body['websiteName'] === 'WEBSTAGING'
            && $body['txnAmount'] === ['value' => '708.50', 'currency' => 'INR']
            && $body['callbackUrl'] === 'https://isp.example/pay/return/paytm'
            && $body['userInfo'] === ['custId' => 'CUST'.test()->payment->account_id];
    });

    expect($start->kind)->toBe('form')
        ->and($start->url)->toBe('https://securestage.paytmpayments.com/theia/api/v1/showPaymentPage?mid=TESTMID&orderId=01JQUICKPAYTESTORDER000001')
        ->and($start->fields)->toBe(['mid' => 'TESTMID', 'orderId' => '01JQUICKPAYTESTORDER000001', 'txnToken' => 'TOKEN123']);
});

it('uses the live host and website by default, or the host set in the settings', function () {
    Http::fake(['*' => Http::response(['body' => ['resultInfo' => ['resultStatus' => 'S'], 'txnToken' => 'T']])]);

    paytm(['environment' => 'live'])->start($this->payment, 'https://isp.example/r');
    paytm(['host' => 'https://pg.example.net/'])->start($this->payment, 'https://isp.example/r');

    $sent = Http::recorded()->map(fn (array $pair): string => $pair[0]->url());
    expect($sent[0])->toStartWith('https://secure.paytmpayments.com/theia/')
        ->and(json_decode((string) preg_replace('/^\{"body":(\{.*\}),"head".*$/', '$1', Http::recorded()[0][0]->body()), true)['websiteName'])->toBe('DEFAULT')
        ->and($sent[1])->toStartWith('https://pg.example.net/theia/');
});

it('reports a refused start without the merchant key', function () {
    Http::fake(['*' => Http::response(['body' => ['resultInfo' => ['resultStatus' => 'F', 'resultCode' => '501', 'resultMsg' => 'System Error']]])]);

    expect(fn () => paytm()->start($this->payment, 'https://isp.example/r'))
        ->toThrow(GatewayUnavailable::class, 'Paytm refused the transaction: 501 System Error');
});

it('reads the order status', function (string $status, GatewayStatus $expected) {
    Http::fake(['*/v3/order/status' => Http::response(['head' => ['signature' => 'x'], 'body' => [
        'resultInfo' => ['resultStatus' => $status, 'resultCode' => '01', 'resultMsg' => 'Txn Success'],
        'txnId' => '2026102111212800110168', 'orderId' => '01JQUICKPAYTESTORDER000001', 'txnAmount' => '708.50', 'txnDate' => '2026-10-21 11:05:00.0', 'paymentMode' => 'UPI',
    ]])]);

    $result = paytm()->status($this->payment);

    Http::assertSent(function (ClientRequest $request): bool {
        preg_match('/^\{"body":(\{.*\}),"head":\{"signature":"([^"]+)"\}\}$/', $request->body(), $parts);

        return str_ends_with($request->url(), '/v3/order/status') && PaytmChecksum::verify($parts[1], PAYTM_KEY, $parts[2])
            && json_decode($parts[1], true) === ['mid' => 'TESTMID', 'orderId' => '01JQUICKPAYTESTORDER000001'];
    });

    expect($result->status)->toBe($expected)
        ->and($result->amount)->toBe('708.50')
        ->and($result->txnId)->toBe('2026102111212800110168')
        ->and($result->providerStatus)->toBe($status)
        ->and($result->paidAt?->format('Y-m-d H:i'))->toBe('2026-10-21 11:05')
        ->and($result->raw)->not->toHaveKey('head');
})->with([
    ['TXN_SUCCESS', GatewayStatus::Paid],
    ['TXN_FAILURE', GatewayStatus::Failed],
    ['PENDING', GatewayStatus::Pending],
    ['NO_RECORD_FOUND', GatewayStatus::Pending],
]);

it('treats an unreachable gateway as pending', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    expect(paytm()->status($this->payment)->status)->toBe(GatewayStatus::Pending);
});

it('accepts a callback Paytm signed and refuses a changed one', function () {
    $fields = ['MID' => 'TESTMID', 'ORDERID' => '01JQUICKPAYTESTORDER000001', 'STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '708.50'];
    $signed = [...$fields, 'CHECKSUMHASH' => PaytmChecksum::signFields($fields, PAYTM_KEY)];

    expect(paytm()->verify(Request::create('/pay/return/paytm', 'POST', $signed)))->toBe('01JQUICKPAYTESTORDER000001')
        ->and(paytm()->verify(Request::create('/pay/return/paytm', 'GET', $signed)))->toBe('01JQUICKPAYTESTORDER000001')
        ->and(paytm()->verify(Request::create('/pay/return/paytm', 'POST', [...$signed, 'TXNAMOUNT' => '1.00'])))->toBeNull()
        ->and(paytm()->verify(Request::create('/pay/return/paytm', 'POST', [...$signed, 'MID' => 'OTHER'])))->toBeNull()
        ->and(paytm()->verify(Request::create('/pay/return/paytm', 'POST', $fields)))->toBeNull();
});

it('accepts a webhook signed over its raw body', function () {
    $body = '{"mid":"TESTMID","orderId":"01JQUICKPAYTESTORDER000001","status":"TXN_SUCCESS","respMsg":"ok {braces} \"quoted\""}';
    $raw = '{"head": {"signature": "'.PaytmChecksum::sign($body, PAYTM_KEY).'"}, "body": '.$body.'}';
    $request = fn (string $content): Request => Request::create('/pay/webhook/paytm', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $content);

    expect(paytm()->verify($request($raw)))->toBe('01JQUICKPAYTESTORDER000001')
        ->and(paytm()->verify($request(str_replace('TXN_SUCCESS', 'TXN_FAILURE', $raw))))->toBeNull()
        ->and(paytm()->verify($request('{"body": '.$body.'}')))->toBeNull()
        ->and(paytm()->verify($request('not json')))->toBeNull();
});
