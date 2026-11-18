<?php

namespace App\Payments\PhonePe;

use App\Contracts\PaymentGateway;
use App\Contracts\UntestedGateway;
use App\Exceptions\GatewayUnavailable;
use App\Models\OnlinePayment;
use App\Notifications\SettingField;
use App\Payments\Concerns\CallsGatewayApi;
use App\Payments\GatewayAmount;
use App\Payments\GatewayResult;
use App\Payments\GatewayStart;
use App\Payments\GatewayStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * PhonePe Payment Gateway, Standard Checkout v2 (redirect to PhonePe's page). Docs, checked 2026-11-18:
 * https://developer.phonepe.com/payment-gateway/website-integration/standard-checkout/api-integration/api-reference/authorization
 * https://developer.phonepe.com/payment-gateway/website-integration/standard-checkout/api-integration/api-reference/create-payment
 * https://developer.phonepe.com/payment-gateway/website-integration/standard-checkout/api-integration/api-reference/order-status
 * https://developer.phonepe.com/payment-gateway/website-integration/standard-checkout/api-integration/api-reference/webhook
 *
 * Nothing the customer's browser or a webhook says is believed: the return only carries our own `attempt` id and the
 * webhook's Authorization header (sha256 of "username:password") only names the order; settling then asks the Order
 * Status API, which is the proof of payment, and its amount (paise) is compared with the attempt's.
 *
 * Still untested: on 2026-11-17 the sandbox status API answered FAILED / ORDER_EXPIRED for an order the PhonePe
 * dashboard showed as paid. The class stays an UntestedGateway until a live Rs 1 payment records correctly.
 */
final class PhonePeGateway implements PaymentGateway, UntestedGateway
{
    use CallsGatewayApi;

    private const HOSTS = ['test' => 'https://api-preprod.phonepe.com/apis/pg-sandbox', 'live' => 'https://api.phonepe.com/apis/pg'];

    private const TOKEN_HOSTS = ['test' => 'https://api-preprod.phonepe.com/apis/pg-sandbox', 'live' => 'https://api.phonepe.com/apis/identity-manager'];

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'phonepe';
    }

    public static function label(): string
    {
        return 'PhonePe';
    }

    public static function currencies(): array
    {
        return ['INR'];
    }

    public static function settingFields(): array
    {
        return [
            new SettingField('environment', __('Environment'), help: __('test or live. Test keys work only with test, live keys only with live.'), maxLength: 4, rules: ['in:test,live']),
            new SettingField('client_id', __('Client ID'), maxLength: 100),
            new SettingField('client_version', __('Client version'), maxLength: 20),
            new SettingField('client_secret', __('Client secret'), 'secret', maxLength: 200),
            new SettingField('webhook_username', __('Webhook username'), help: __('The username you set for the webhook in the PhonePe dashboard.'), maxLength: 100),
            new SettingField('webhook_password', __('Webhook password'), 'secret', help: __('The password you set for the webhook in the PhonePe dashboard.'), maxLength: 100),
            // https only: the replies decide whether a payment counts as paid.
            new SettingField('host', __('PhonePe address'), 'url', required: false, help: __('Leave it empty. Fill it in only if :gateway tells you (for example by email) that this address has changed: paste the new address here.', ['gateway' => 'PhonePe']), maxLength: 200, rules: ['url:https']),
        ];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        $response = $this->authorizedJson('post', $this->host().'/checkout/v2/pay', [
            'merchantOrderId' => $payment->public_id,
            'amount' => GatewayAmount::minor((string) $payment->amount),
            'expireAfter' => 1200,
            'paymentFlow' => ['type' => 'PG_CHECKOUT', 'merchantUrls' => ['redirectUrl' => $returnUrl]],
        ]);

        $url = $response['redirectUrl'] ?? null;
        $orderId = $response['orderId'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new GatewayUnavailable('PhonePe answered without a payment page.');
        }

        return GatewayStart::redirect($url, is_string($orderId) && $orderId !== '' ? $orderId : null);
    }

    /**
     * PhonePe's amount is in paise and INR is the only currency this gateway charges in, so the currency is the
     * answer's own when it names one, else INR.
     */
    public function status(OnlinePayment $payment): GatewayResult
    {
        try {
            $order = $this->authorizedJson('get', $this->host().'/checkout/v2/order/'.rawurlencode($payment->public_id).'/status?details=true');
            $amount = $this->amount($order['amount'] ?? null);
        } catch (GatewayUnavailable) {
            return new GatewayResult(GatewayStatus::Pending);
        }

        $state = is_string($order['state'] ?? null) ? $order['state'] : '';
        $answeredOrder = $order['orderId'] ?? null;

        if ($payment->gateway_order_id !== null && is_string($answeredOrder) && $answeredOrder !== $payment->gateway_order_id) {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: 'ORDER_MISMATCH');
        }

        $completed = $this->completedAttempt($order);

        return new GatewayResult(
            status: match ($state) {
                'COMPLETED' => $amount === null ? GatewayStatus::Pending : GatewayStatus::Paid,
                'FAILED' => GatewayStatus::Failed,
                default => GatewayStatus::Pending,
            },
            amount: $amount,
            txnId: is_string($completed['transactionId'] ?? null) && $completed['transactionId'] !== '' ? $completed['transactionId'] : null,
            paidAt: $this->date($completed['timestamp'] ?? null),
            providerStatus: $state === '' ? null : $state,
            raw: $this->safeRaw($order),
            currency: is_string($order['currency'] ?? null) && $order['currency'] !== '' ? $order['currency'] : 'INR',
        );
    }

    /**
     * Only names an attempt: the return is unsigned, so its `attempt` parameter is just looked up; the webhook must
     * carry the Authorization header PhonePe computes from the username and password set in its dashboard, and names
     * the order in payload.merchantOrderId. Nothing else in either request is read.
     */
    public function verify(Request $request): ?string
    {
        if ($request->is('pay/webhook/*')) {
            $username = $this->setting('webhook_username');
            $password = $this->setting('webhook_password');
            $header = $request->header('Authorization');

            if ($username === '' || $password === '' || ! is_string($header) || ! hash_equals(hash('sha256', $username.':'.$password), strtolower(trim($header)))) {
                return null;
            }

            $order = $request->json('payload.merchantOrderId');

            return is_string($order) ? $this->attempt($order) : null;
        }

        $attempt = $request->query('attempt');

        return is_string($attempt) ? $this->attempt($attempt) : null;
    }

    private function attempt(string $publicId): ?string
    {
        return $publicId !== '' && OnlinePayment::query()->where('public_id', $publicId)->where('gateway', self::key())->exists() ? $publicId : null;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function completedAttempt(array $order): array
    {
        $details = is_array($order['paymentDetails'] ?? null) ? $order['paymentDetails'] : [];

        foreach ($details as $detail) {
            if (is_array($detail) && ($detail['state'] ?? null) === 'COMPLETED') {
                return $detail;
            }
        }

        return [];
    }

    /**
     * @throws GatewayUnavailable
     */
    private function amount(mixed $paise): ?string
    {
        if (! is_int($paise) && ! (is_string($paise) && preg_match('/^\d+$/', $paise) === 1)) {
            return null;
        }

        return GatewayAmount::fromMinor($paise);
    }

    /**
     * What is kept on the attempt: states, amounts and ids, never the card or bank instrument.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function safeRaw(array $order): array
    {
        $details = [];

        foreach (is_array($order['paymentDetails'] ?? null) ? $order['paymentDetails'] : [] as $detail) {
            if (is_array($detail)) {
                $details[] = array_intersect_key($detail, array_flip(['paymentMode', 'timestamp', 'amount', 'transactionId', 'state', 'errorCode', 'detailedErrorCode']));
            }
        }

        return [...array_intersect_key($order, array_flip(['orderId', 'state', 'amount', 'currency', 'expireAt', 'errorCode', 'detailedErrorCode'])), 'paymentDetails' => $details];
    }

    /**
     * Calls the API with the cached token. A 401 means the token went stale early: it is forgotten and the call is
     * made once more with a fresh one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailable
     */
    private function authorizedJson(string $method, string $url, array $payload = []): array
    {
        try {
            return $this->sendJson($this->authorized(), $method, $url, $payload);
        } catch (GatewayUnavailable $exception) {
            if ($exception->httpStatus !== 401) {
                throw $exception;
            }
        }

        Cache::forget($this->tokenCacheKey());

        return $this->sendJson($this->authorized(), $method, $url, $payload);
    }

    /**
     * @throws GatewayUnavailable
     */
    private function authorized(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'O-Bearer '.$this->token()]);
    }

    private function tokenCacheKey(): string
    {
        return 'phonepe.token.'.$this->environment().'.'.sha1($this->setting('client_id').'|'.$this->tokenHost());
    }

    /**
     * The OAuth token, kept until a minute before it expires. The key names the client, environment and host, so
     * another account or environment never reuses it. Only the token is cached, never the secret.
     *
     * @throws GatewayUnavailable
     */
    private function token(): string
    {
        $cacheKey = $this->tokenCacheKey();
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post($this->tokenHost().'/v1/oauth/token', [
                'client_id' => $this->setting('client_id'),
                'client_version' => $this->setting('client_version'),
                'client_secret' => $this->setting('client_secret'),
                'grant_type' => 'client_credentials',
            ]);
        } catch (HttpClientException) {
            throw new GatewayUnavailable('PhonePe could not be reached for a token.');
        }

        $token = $response->json('access_token');
        $expiresAt = $response->json('expires_at');

        if (! $response->successful() || ! is_string($token) || $token === '' || ! is_numeric($expiresAt)) {
            throw new GatewayUnavailable('PhonePe did not give a token (HTTP '.$response->status().').');
        }

        $until = CarbonImmutable::createFromTimestamp((int) $expiresAt)->subSeconds(60);

        if ($until->isFuture()) {
            Cache::put($cacheKey, $token, $until);
        }

        return $token;
    }

    private function host(): string
    {
        $host = rtrim($this->setting('host'), '/');

        return $host !== '' ? $host : self::HOSTS[$this->environment()];
    }

    /**
     * Live OAuth lives on another path than the API. With a host override the token comes from the same host.
     */
    private function tokenHost(): string
    {
        $host = rtrim($this->setting('host'), '/');

        return $host !== '' ? $host : self::TOKEN_HOSTS[$this->environment()];
    }

    /**
     * @return 'test'|'live'
     */
    private function environment(): string
    {
        return $this->setting('environment') === 'live' ? 'live' : 'test';
    }

    private function setting(string $name): string
    {
        return trim($this->settings[$name] ?? '');
    }

    /**
     * PhonePe's timestamps are epoch milliseconds.
     */
    private function date(mixed $millis): ?CarbonImmutable
    {
        return is_int($millis) && $millis > 0 ? CarbonImmutable::createFromTimestampMs($millis) : null;
    }
}
