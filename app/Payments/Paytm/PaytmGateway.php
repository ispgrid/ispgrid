<?php

namespace App\Payments\Paytm;

use App\Contracts\PaymentGateway;
use App\Exceptions\GatewayUnavailable;
use App\Models\OnlinePayment;
use App\Notifications\SettingField;
use App\Payments\GatewayResult;
use App\Payments\GatewayStart;
use App\Payments\GatewayStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Paytm Payment Gateway (hosted payment page). Starts a transaction with Initiate Transaction and posts the
 * customer to Paytm's page; the Transaction Status API is the proof of payment. Paytm moved its hosts before, so
 * the host follows the Environment setting and can be overridden. Sends Paytm no name, email or phone: custId is
 * the account id.
 */
final class PaytmGateway implements PaymentGateway
{
    private const HOSTS = ['test' => 'https://securestage.paytmpayments.com', 'live' => 'https://secure.paytmpayments.com'];

    private const WEBSITES = ['test' => 'WEBSTAGING', 'live' => 'DEFAULT'];

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'paytm';
    }

    public static function label(): string
    {
        return 'Paytm';
    }

    public static function currencies(): array
    {
        return ['INR'];
    }

    public static function settingFields(): array
    {
        return [
            new SettingField('environment', __('Environment'), help: __('test or live. Test keys work only with test, live keys only with live.'), maxLength: 4, rules: ['in:test,live']),
            new SettingField('mid', __('Merchant ID (MID)'), maxLength: 50),
            new SettingField('merchant_key', __('Merchant key'), 'secret', maxLength: 100),
            new SettingField('website_name', __('Website name'), required: false, help: __('Empty: WEBSTAGING for test, DEFAULT for live. Change it only if Paytm gave you another.'), maxLength: 50),
            new SettingField('host', __('Paytm address'), 'url', required: false, help: __('Empty: the address Paytm documents for the environment. Set it only if Paytm moves.'), maxLength: 200),
        ];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        $mid = $this->mid();
        $response = $this->call("/theia/api/v1/initiateTransaction?mid={$mid}&orderId={$payment->public_id}", [
            'requestType' => 'Payment',
            'mid' => $mid,
            'websiteName' => $this->setting('website_name') ?: self::WEBSITES[$this->environment()],
            'orderId' => $payment->public_id,
            'callbackUrl' => $returnUrl,
            'txnAmount' => ['value' => number_format((float) $payment->amount, 2, '.', ''), 'currency' => $payment->currency],
            'userInfo' => ['custId' => 'CUST'.$payment->account_id],
        ]);

        $result = is_array($response['body']['resultInfo'] ?? null) ? $response['body']['resultInfo'] : [];
        $token = $response['body']['txnToken'] ?? null;

        if (($result['resultStatus'] ?? null) !== 'S' || ! is_string($token) || $token === '') {
            throw new GatewayUnavailable(trim('Paytm refused the transaction: '.($result['resultCode'] ?? '?').' '.($result['resultMsg'] ?? '')));
        }

        return GatewayStart::form($this->host()."/theia/api/v1/showPaymentPage?mid={$mid}&orderId={$payment->public_id}", [
            'mid' => $mid,
            'orderId' => $payment->public_id,
            'txnToken' => $token,
        ]);
    }

    public function status(OnlinePayment $payment): GatewayResult
    {
        try {
            $response = $this->call('/v3/order/status', ['mid' => $this->mid(), 'orderId' => $payment->public_id]);
        } catch (GatewayUnavailable) {
            return new GatewayResult(GatewayStatus::Pending);
        }

        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $state = (string) ($body['resultInfo']['resultStatus'] ?? '');
        $amount = $body['txnAmount'] ?? null;

        return new GatewayResult(
            status: match ($state) {
                'TXN_SUCCESS' => GatewayStatus::Paid,
                'TXN_FAILURE' => GatewayStatus::Failed,
                default => GatewayStatus::Pending,
            },
            amount: is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : null,
            txnId: isset($body['txnId']) && is_string($body['txnId']) && $body['txnId'] !== '' ? $body['txnId'] : null,
            paidAt: $this->date($body['txnDate'] ?? null),
            providerStatus: $state === '' ? null : $state,
            raw: $body,
        );
    }

    public function verify(Request $request): ?string
    {
        $checksum = $request->input('CHECKSUMHASH');

        if (is_string($checksum)) {
            $fields = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $request->except('CHECKSUMHASH'));
            $order = $fields['ORDERID'] ?? null;

            return ($fields['MID'] ?? null) === $this->mid() && $order !== null && $order !== ''
                && PaytmChecksum::verifyFields($fields, $this->setting('merchant_key'), $checksum) ? $order : null;
        }

        $raw = $request->getContent();
        $decoded = json_decode($raw, true);
        $signature = is_array($decoded) ? ($decoded['head']['signature'] ?? null) : null;
        $body = self::rawBody($raw);

        if (! is_string($signature) || $body === null || ! PaytmChecksum::verify($body, $this->setting('merchant_key'), $signature)) {
            return null;
        }

        $fields = json_decode($body, true);
        $order = is_array($fields) ? ($fields['orderId'] ?? null) : null;

        return is_array($fields) && ($fields['mid'] ?? null) === $this->mid() && is_string($order) && $order !== '' ? $order : null;
    }

    /**
     * The exact text of the "body" object in a webhook: Paytm signs those bytes, so re-encoding it would break the
     * signature. Walks the braces, skipping those inside strings.
     */
    public static function rawBody(string $raw): ?string
    {
        if (preg_match('/"body"\s*:\s*\{/', $raw, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]) - 1;
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start, $length = strlen($raw); $i < $length; $i++) {
            $char = $raw[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
            } elseif ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return substr($raw, $start, $i - $start + 1);
            }
        }

        return null;
    }

    /**
     * POSTs {"body": ..., "head": {"signature": ...}} and returns the decoded answer.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $path, array $body): array
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $payload = '{"body":'.$json.',"head":{"signature":"'.PaytmChecksum::sign($json, $this->setting('merchant_key')).'"}}';

        try {
            $response = Http::withBody($payload, 'application/json')->acceptJson()->timeout(20)->post($this->host().$path);
        } catch (ConnectionException $exception) {
            throw new GatewayUnavailable('Paytm could not be reached: '.$exception->getMessage());
        }

        $decoded = $response->json();

        if (! $response->successful() || ! is_array($decoded)) {
            throw new GatewayUnavailable('Paytm answered HTTP '.$response->status().'.');
        }

        return $decoded;
    }

    private function host(): string
    {
        $host = rtrim($this->setting('host'), '/');

        return $host !== '' ? $host : self::HOSTS[$this->environment()];
    }

    /**
     * @return 'test'|'live'
     */
    private function environment(): string
    {
        return $this->setting('environment') === 'live' ? 'live' : 'test';
    }

    private function mid(): string
    {
        return $this->setting('mid');
    }

    private function setting(string $name): string
    {
        return trim($this->settings[$name] ?? '');
    }

    /**
     * Paytm's txnDate ("2026-10-21 11:05:00.0") is Indian time.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Asia/Kolkata')->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
