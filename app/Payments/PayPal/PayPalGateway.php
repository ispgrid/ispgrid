<?php

namespace App\Payments\PayPal;

use App\Contracts\PaymentGateway;
use App\Contracts\UntestedGateway;
use App\Exceptions\GatewayUnavailable;
use App\Models\OnlinePayment;
use App\Notifications\SettingField;
use App\Payments\Concerns\CallsGatewayApi;
use App\Payments\EnvironmentField;
use App\Payments\GatewayAmount;
use App\Payments\GatewayResult;
use App\Payments\GatewayStart;
use App\Payments\GatewayStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * PayPal Checkout, Orders v2 with buyer approval, then capture. Docs (the pages were only partly readable from here,
 * so the field names follow PayPal's published reference as known, and the sandbox is the final check):
 * https://developer.paypal.com/api/rest/authentication/ (POST /v1/oauth2/token, basic auth, client_credentials)
 * https://developer.paypal.com/docs/api/orders/v2/ (create, show and capture an order; payment_source.paypal.experience_context)
 * https://developer.paypal.com/api/rest/requests/#http-request-headers (PayPal-Request-Id makes a retried call idempotent)
 * https://developer.paypal.com/api/rest/reference/currency-codes/ (HUF, JPY and TWD take no decimals)
 * https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature (POST /v1/notifications/verify-webhook-signature)
 *
 * Where this differs from the task brief: nothing known; the brief's "approve" link is taken as a fallback for the
 * "payer-action" link the paypal payment source returns.
 *
 * Nothing the browser or a webhook says is believed. The return carries no signature, so it only names an attempt
 * through our own `attempt` parameter (PayPal's `token`, the order id, must agree with the attempt and is never used
 * to find it). A webhook only names an attempt after PayPal itself confirms its signature. Settling reads the order
 * from PayPal by the id we stored; an approved order is captured once (under a lock, with the PayPal-Request-Id of the
 * attempt) only when its amount and currency equal the attempt's, and the amount and currency that count are those of
 * the COMPLETED capture in PayPal's answer.
 */
final class PayPalGateway implements PaymentGateway, UntestedGateway
{
    use CallsGatewayApi;

    private const HOSTS = ['test' => 'https://api-m.sandbox.paypal.com', 'live' => 'https://api-m.paypal.com'];

    private const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD'];

    /** Amounts of these are sent and read in whole units. */
    private const ZERO_DECIMAL = ['HUF', 'JPY', 'TWD'];

    private const WEBHOOK_HEADERS = ['PAYPAL-AUTH-ALGO' => 'auth_algo', 'PAYPAL-CERT-URL' => 'cert_url', 'PAYPAL-TRANSMISSION-ID' => 'transmission_id',
        'PAYPAL-TRANSMISSION-SIG' => 'transmission_sig', 'PAYPAL-TRANSMISSION-TIME' => 'transmission_time'];

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'paypal';
    }

    public static function label(): string
    {
        return 'PayPal';
    }

    public static function currencies(): array
    {
        return self::CURRENCIES;
    }

    public static function settingFields(): array
    {
        return [
            EnvironmentField::make(__('Test uses the PayPal sandbox. Sandbox credentials work only with Test, live credentials only with Live.')),
            new SettingField('client_id', __('Client ID'), maxLength: 200),
            new SettingField('client_secret', __('Client secret'), 'secret', maxLength: 300),
            new SettingField('webhook_id', __('Webhook ID'), help: __('The ID of the webhook you add in the PayPal developer dashboard. Select these events: Checkout order approved, Payment capture completed.'), maxLength: 100),
            // https only: the replies decide whether a payment counts as paid.
            new SettingField('host', __('PayPal address'), 'url', required: false, help: __('Leave it empty. Fill it in only if :gateway tells you (for example by email) that this address has changed: paste the new address here.', ['gateway' => 'PayPal']), maxLength: 200, rules: ['url:https']),
        ];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        $currency = strtoupper($payment->currency);
        $value = $this->value((string) $payment->amount, $currency);

        $order = $this->authorizedJson('post', $this->host().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'custom_id' => $payment->public_id,
                'amount' => ['currency_code' => $currency, 'value' => $value],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => ['return_url' => $returnUrl, 'cancel_url' => $returnUrl, 'user_action' => 'PAY_NOW']]],
        ], ['PayPal-Request-Id' => 'start-'.$payment->public_id]);

        $id = $order['id'] ?? null;
        $url = $this->link($order, 'payer-action') ?? $this->link($order, 'approve');

        if (! is_string($id) || $id === '' || $url === null) {
            throw new GatewayUnavailable('PayPal answered without an order id or an approval link.');
        }

        return GatewayStart::redirect($url, $id);
    }

    /**
     * Pending whenever PayPal cannot be reached or the order is not final. Paid only for a COMPLETED capture, Failed
     * only for a VOIDED order.
     */
    public function status(OnlinePayment $payment): GatewayResult
    {
        $orderId = $payment->gateway_order_id;

        if ($orderId === null || $orderId === '') {
            return new GatewayResult(GatewayStatus::Pending);
        }

        try {
            $order = $this->order($orderId);

            if (($order['status'] ?? null) === 'APPROVED') {
                $order = $this->capture($orderId, $payment);
            }
        } catch (GatewayUnavailable) {
            return new GatewayResult(GatewayStatus::Pending);
        }

        if ($order === null) {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: 'APPROVED');
        }

        $state = is_string($order['status'] ?? null) ? $order['status'] : null;
        $raw = $this->safeRaw($order);

        if ($state === 'VOIDED') {
            return new GatewayResult(GatewayStatus::Failed, providerStatus: $state, raw: $raw);
        }

        if ($state !== 'COMPLETED') {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $state, raw: $raw);
        }

        $captures = $this->captures($order);
        $done = null;

        foreach ($captures as $capture) {
            if (($capture['status'] ?? null) === 'COMPLETED') {
                $done = $capture;
                break;
            }
        }

        $amount = $done === null ? null : $this->read($done['amount'] ?? null);

        if ($done === null || $amount === null) {
            $first = $captures[0]['status'] ?? null;

            return new GatewayResult(GatewayStatus::Pending, providerStatus: is_string($first) ? $first : $state, raw: $raw);
        }

        return new GatewayResult(
            status: GatewayStatus::Paid,
            amount: $amount[0],
            txnId: is_string($done['id'] ?? null) && $done['id'] !== '' ? $done['id'] : null,
            paidAt: $this->date($done['create_time'] ?? null),
            providerStatus: 'COMPLETED',
            raw: $raw,
            currency: $amount[1],
        );
    }

    /**
     * Only names an attempt. The return is looked up by our `attempt` parameter; PayPal's `token` (the order id), when
     * present, must be the one saved on that attempt. A webhook must name a PayPal attempt of ours (order id, and our custom_id when the
     * event has it) before PayPal is asked anything, and then needs PayPal to confirm its signature.
     */
    public function verify(Request $request): ?string
    {
        if ($request->is('pay/webhook/*')) {
            return $this->verifyWebhook($request);
        }

        $attempt = $request->query('attempt');
        $token = $request->query('token');

        if (! is_string($attempt) || $attempt === '' || ($token !== null && (! is_string($token) || $token === ''))) {
            return null;
        }

        $query = OnlinePayment::query()->where('public_id', $attempt)->where('gateway', self::key());

        if ($token !== null) {
            $query->where('gateway_order_id', $token);
        }

        return $query->exists() ? $attempt : null;
    }

    private function verifyWebhook(Request $request): ?string
    {
        $webhookId = $this->setting('webhook_id');
        $event = $request->json()->all();
        $body = ['webhook_id' => $webhookId];

        foreach (self::WEBHOOK_HEADERS as $header => $field) {
            $value = $request->header($header);

            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            $body[$field] = trim($value);
        }

        if ($webhookId === '' || $event === []) {
            return null;
        }

        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $unit = is_array($resource['purchase_units'][0] ?? null) ? $resource['purchase_units'][0] : [];
        $order = $resource['supplementary_data']['related_ids']['order_id'] ?? (str_starts_with((string) ($event['event_type'] ?? ''), 'CHECKOUT.ORDER.') ? ($resource['id'] ?? null) : null);
        $custom = $resource['custom_id'] ?? $unit['custom_id'] ?? null;
        $order = is_string($order) && $order !== '' ? $order : null;
        $custom = is_string($custom) && $custom !== '' ? $custom : null;

        if ($order === null) {
            return null;
        }

        $query = OnlinePayment::query()->where('gateway', self::key())->where('gateway_order_id', $order);

        if ($custom !== null) {
            $query->where('public_id', $custom);
        }

        $found = $query->value('public_id');

        if (! is_string($found)) {
            return null;
        }

        try {
            // The event goes in as received: PayPal checks the signature over its exact bytes.
            $raw = substr((string) json_encode($body), 0, -1).',"webhook_event":'.$request->getContent().'}';
            $answer = $this->authorizedJson('post', $this->host().'/v1/notifications/verify-webhook-signature', rawBody: $raw);
        } catch (GatewayUnavailable) {
            return null;
        }

        if (($answer['verification_status'] ?? null) !== 'SUCCESS') {
            return null;
        }

        return $found;
    }

    /**
     * Captures an approved order once. Under a lock per order (a return and a webhook can settle at the same moment)
     * the order is read again: a COMPLETED one wins and nothing is captured. The capture is made only when the order's
     * amount and currency equal the attempt's, with a PayPal-Request-Id derived from the attempt so a retry is the same
     * call to PayPal. A failed capture call re-reads the order instead of trusting anything. Without the lock the
     * answer is null (Pending); when nothing was captured it is the order as last read (APPROVED means Pending).
     *
     * @return array<string, mixed>|null
     *
     * @throws GatewayUnavailable
     */
    private function capture(string $orderId, OnlinePayment $payment): ?array
    {
        try {
            return Cache::lock('paypal-capture:'.$orderId, 30)->block(10, fn (): array => $this->captureLocked($orderId, $payment));
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailable
     */
    private function captureLocked(string $orderId, OnlinePayment $payment): array
    {
        $order = $this->order($orderId);

        if (($order['status'] ?? null) !== 'APPROVED' || ! $this->orderMatches($order, $payment)) {
            return $order;
        }

        try {
            $answer = $this->authorizedJson('post', $this->host().'/v2/checkout/orders/'.rawurlencode($orderId).'/capture', [], ['PayPal-Request-Id' => 'capture-'.$payment->public_id, 'Prefer' => 'return=representation']);

            if (($answer['id'] ?? null) === $orderId && is_string($answer['status'] ?? null)) {
                return $answer;
            }
        } catch (GatewayUnavailable) {
            // fall through to the re-read
        }

        return $this->order($orderId);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailable
     */
    private function order(string $orderId): array
    {
        $order = $this->authorizedJson('get', $this->host().'/v2/checkout/orders/'.rawurlencode($orderId));

        if (($order['id'] ?? null) !== $orderId) {
            throw new GatewayUnavailable('PayPal answered for another order.');
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderMatches(array $order, OnlinePayment $payment): bool
    {
        $unit = $order['purchase_units'][0] ?? null;
        $read = is_array($unit) ? $this->read($unit['amount'] ?? null) : null;

        try {
            $expected = GatewayAmount::twoDecimals($this->value((string) $payment->amount, strtoupper($payment->currency)));
        } catch (GatewayUnavailable) {
            return false;
        }

        return $read !== null && $read[1] === strtoupper($payment->currency) && $read[0] === $expected;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    private function captures(array $order): array
    {
        $items = $order['purchase_units'][0]['payments']['captures'] ?? null;

        return is_array($items) ? array_values(array_filter($items, is_array(...))) : [];
    }

    /**
     * An amount object from PayPal as [two-decimal amount, upper-case currency], or null when it is not a plain one.
     *
     * @return array{0: string, 1: string}|null
     */
    private function read(mixed $amount): ?array
    {
        $currency = is_array($amount) ? ($amount['currency_code'] ?? null) : null;
        $value = is_array($amount) ? ($amount['value'] ?? null) : null;

        if (! is_string($currency) || preg_match('/^[A-Za-z]{3}$/', $currency) !== 1 || ! is_string($value) || preg_match('/^\d+(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        $currency = strtoupper($currency);

        if (in_array($currency, self::ZERO_DECIMAL, true) && str_contains($value, '.')) {
            return null;
        }

        try {
            return [GatewayAmount::twoDecimals($value), $currency];
        } catch (GatewayUnavailable) {
            return null;
        }
    }

    /**
     * The value PayPal takes: two decimals, or none for HUF, JPY and TWD (a fraction of those is refused locally).
     *
     * @throws GatewayUnavailable
     */
    private function value(string $amount, string $currency): string
    {
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return (string) GatewayAmount::minor($amount, true);
        }

        return GatewayAmount::twoDecimals($amount);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function link(array $order, string $rel): ?string
    {
        foreach (is_array($order['links'] ?? null) ? $order['links'] : [] as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === $rel && is_string($link['href'] ?? null) && $this->isPayPalUrl($link['href'])) {
                return $link['href'];
            }
        }

        return null;
    }

    private function isPayPalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return str_starts_with($url, 'https://') && is_string($host) && ($host === 'paypal.com' || str_ends_with($host, '.paypal.com'));
    }

    /**
     * What is kept on the attempt: ids, states and amounts, never the payer, address or payment source.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function safeRaw(array $order): array
    {
        $unit = is_array($order['purchase_units'][0] ?? null) ? $order['purchase_units'][0] : [];
        $amount = is_array($unit['amount'] ?? null) ? array_intersect_key($unit['amount'], array_flip(['currency_code', 'value'])) : null;
        $captures = [];

        foreach ($this->captures($order) as $capture) {
            $kept = array_intersect_key($capture, array_flip(['id', 'status', 'create_time', 'final_capture']));
            $kept['amount'] = is_array($capture['amount'] ?? null) ? array_intersect_key($capture['amount'], array_flip(['currency_code', 'value'])) : null;
            $captures[] = $kept;
        }

        return [...array_intersect_key($order, array_flip(['id', 'status', 'intent'])), 'amount' => $amount, 'captures' => $captures];
    }

    private function date(mixed $time): ?CarbonImmutable
    {
        if (! is_string($time) || $time === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($time);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Calls the API with the cached token. A 401 means the token went stale early: it is forgotten and the call is
     * made once more with a fresh one.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @param  string|null  $rawBody  a JSON body sent as is
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailable
     */
    private function authorizedJson(string $method, string $url, array $payload = [], array $headers = [], ?string $rawBody = null): array
    {
        $send = fn (): array => $rawBody === null
            ? $this->sendJson($this->authorized($headers), $method, $url, $payload)
            : $this->dispatchRequest($this->authorized($headers), strtoupper($method), $url, ['body' => $rawBody, 'headers' => ['Content-Type' => 'application/json']]);

        try {
            return $send();
        } catch (GatewayUnavailable $exception) {
            if ($exception->httpStatus !== 401) {
                throw $exception;
            }
        }

        Cache::forget($this->tokenCacheKey());

        return $send();
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws GatewayUnavailable
     */
    private function authorized(array $headers): PendingRequest
    {
        return Http::withToken($this->token())->withHeaders($headers);
    }

    private function tokenCacheKey(): string
    {
        return 'paypal.token.'.$this->environment().'.'.sha1($this->setting('client_id').'|'.$this->host());
    }

    /**
     * The OAuth token, kept until a minute before it expires. The key names the client, environment and host. Only
     * the token is cached, never the secret.
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
            $response = Http::withBasicAuth($this->setting('client_id'), $this->setting('client_secret'))->asForm()->acceptJson()->timeout(20)
                ->post($this->host().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (HttpClientException) {
            throw new GatewayUnavailable('PayPal could not be reached for a token.');
        }

        $token = $response->json('access_token');
        $lifetime = $response->json('expires_in');

        if (! $response->successful() || ! is_string($token) || $token === '' || ! is_numeric($lifetime)) {
            throw new GatewayUnavailable('PayPal did not give a token (HTTP '.$response->status().').');
        }

        $until = now()->addSeconds((int) $lifetime - 60);

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
}
