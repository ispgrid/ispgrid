<?php

namespace App\Payments\Stripe;

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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout (hosted page). Docs, checked 2026-11-18:
 * https://docs.stripe.com/api/checkout/sessions/create
 * https://docs.stripe.com/api/checkout/sessions/retrieve
 * https://docs.stripe.com/payments/checkout/how-checkout-works (success_url takes the {CHECKOUT_SESSION_ID} placeholder)
 * https://docs.stripe.com/currencies (zero-decimal currencies, minimum and maximum amounts, presentment currencies)
 * https://docs.stripe.com/webhooks#verify-manually (Stripe-Signature: t=, one or more v1=, HMAC-SHA256 of "t.rawBody",
 * constant-time compare, 5 minutes tolerance)
 *
 * Where the docs differ from the task brief: UGX is not in the zero-decimal list here. The currencies page says UGX
 * (like ISK) is sent as a two-decimal figure ("5 UGX is 500") for backward compatibility, so it takes the ordinary path.
 * The page's currency list is rendered as links that could not be read, so CURRENCIES follows Stripe's published
 * presentment list from memory without the three-decimal currencies; Stripe itself refuses a currency the account
 * cannot present. Amounts below Stripe's minimum or above its maximum are refused by Stripe (GatewayUnavailable).
 *
 * Nothing the browser or a webhook says is believed. The return carries no signature, so it only names an attempt
 * through our own `attempt` parameter (the `session_id` in the URL is never used to look anything up). The webhook
 * signature only names an attempt too, through data.object.client_reference_id, and only when data.object.id is the
 * session we opened for it. Settling reads the session from Stripe (GET /v1/checkout/sessions/{id}) by the id we
 * stored; the amount and currency come from that answer.
 */
final class StripeGateway implements PaymentGateway, UntestedGateway
{
    use CallsGatewayApi;

    private const HOST = 'https://api.stripe.com';

    private const TOLERANCE_SECONDS = 300;

    /** Zero-decimal currencies: the amount is sent in whole units. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Presentment currencies without the three-decimal ones (BHD, JOD, KWD, OMR, TND). */
    private const CURRENCIES = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD',
        'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD',
        'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JPY', 'KES', 'KGS',
        'KHR', 'KMF', 'KRW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK',
        'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RWF',
        'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD',
        'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW',
    ];

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'stripe';
    }

    public static function label(): string
    {
        return 'Stripe';
    }

    public static function currencies(): array
    {
        return self::CURRENCIES;
    }

    public static function settingFields(): array
    {
        return [
            new SettingField('environment', __('Environment'), help: __('test (Stripe test mode) or live. A label only: the secret key decides the mode.'), maxLength: 4, rules: ['in:test,live']),
            new SettingField('secret_key', __('Secret key'), 'secret', help: __('The Stripe secret key (sk_...) or a restricted key that can read and create Checkout Sessions.'), maxLength: 200),
            new SettingField('webhook_secret', __('Webhook signing secret'), 'secret', help: __('The signing secret (whsec_...) of the webhook endpoint you add in the Stripe dashboard.'), maxLength: 200),
            // https only: the replies decide whether a payment counts as paid.
            new SettingField('host', __('Stripe address'), 'url', required: false, help: __('Empty: the address Stripe documents. Set it only if Stripe moves.'), maxLength: 200, rules: ['url:https']),
        ];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        $currency = strtoupper($payment->currency);
        $minor = GatewayAmount::minor((string) $payment->amount, in_array($currency, self::ZERO_DECIMAL, true));

        $session = $this->call('post', '/v1/checkout/sessions', [
            'mode' => 'payment',
            'line_items[0][price_data][currency]' => strtolower($currency),
            'line_items[0][price_data][unit_amount]' => (string) $minor,
            'line_items[0][price_data][product_data][name]' => 'Internet bill',
            'line_items[0][quantity]' => '1',
            'client_reference_id' => $payment->public_id,
            'success_url' => $returnUrl.'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $returnUrl,
        ]);

        $id = $session['id'] ?? null;
        $url = $session['url'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new GatewayUnavailable('Stripe answered without a session id or checkout url.');
        }

        return GatewayStart::redirect($url, $id);
    }

    /**
     * Pending whenever Stripe cannot be reached or the session is not in a final state. Paid only for payment_status
     * "paid" (no_payment_required is not a payment), Failed only for an expired session.
     */
    public function status(OnlinePayment $payment): GatewayResult
    {
        $sessionId = $payment->gateway_order_id;

        if ($sessionId === null || $sessionId === '') {
            return new GatewayResult(GatewayStatus::Pending);
        }

        try {
            $session = $this->call('get', '/v1/checkout/sessions/'.rawurlencode($sessionId));
        } catch (GatewayUnavailable) {
            return new GatewayResult(GatewayStatus::Pending);
        }

        $paymentStatus = is_string($session['payment_status'] ?? null) ? $session['payment_status'] : null;
        $state = is_string($session['status'] ?? null) ? $session['status'] : null;
        $raw = array_intersect_key($session, array_flip(['id', 'status', 'payment_status', 'amount_total', 'currency', 'payment_intent']));
        $provider = $paymentStatus ?? $state;

        if (($session['id'] ?? null) !== $sessionId) {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $provider, raw: $raw);
        }

        if ($paymentStatus !== 'paid') {
            return new GatewayResult($state === 'expired' ? GatewayStatus::Failed : GatewayStatus::Pending, providerStatus: $provider, raw: $raw);
        }

        $total = $session['amount_total'] ?? null;
        $currency = $session['currency'] ?? null;

        if (! is_int($total) || $total < 0 || ! is_string($currency) || preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $provider, raw: $raw);
        }

        $currency = strtoupper($currency);

        try {
            $amount = GatewayAmount::fromMinor($total, in_array($currency, self::ZERO_DECIMAL, true));
        } catch (GatewayUnavailable) {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $provider, raw: $raw);
        }

        $intent = $session['payment_intent'] ?? null;

        return new GatewayResult(
            status: GatewayStatus::Paid,
            amount: $amount,
            txnId: is_string($intent) && $intent !== '' ? $intent : null,
            providerStatus: $provider,
            raw: $raw,
            currency: $currency,
        );
    }

    /**
     * Only names an attempt. The return has no signature, so it is looked up by our `attempt` parameter (a session_id
     * in the URL must agree with the attempt, and is never used to find it). The webhook needs a valid Stripe-Signature.
     */
    public function verify(Request $request): ?string
    {
        if ($request->is('pay/webhook/*')) {
            if (! $this->validSignature($request)) {
                return null;
            }

            $object = $request->json('data.object');
            $session = is_array($object) ? ($object['id'] ?? null) : null;
            $reference = is_array($object) ? ($object['client_reference_id'] ?? null) : null;

            if (! is_string($session) || $session === '' || ! is_string($reference) || $reference === '') {
                return null;
            }

            return OnlinePayment::query()->where('public_id', $reference)->where('gateway', self::key())->where('gateway_order_id', $session)->exists() ? $reference : null;
        }

        $attempt = $request->query('attempt');

        if (! is_string($attempt) || $attempt === '') {
            return null;
        }

        $query = OnlinePayment::query()->where('public_id', $attempt)->where('gateway', self::key());
        $session = $request->query('session_id');

        if ($session !== null && (! is_string($session) || $session === '')) {
            return null;
        }

        if ($session !== null) {
            $query->where('gateway_order_id', $session);
        }

        return $query->exists() ? $attempt : null;
    }

    private function validSignature(Request $request): bool
    {
        $secret = $this->setting('webhook_secret');
        $header = $request->header('Stripe-Signature');

        if ($secret === '' || ! is_string($header) || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't') {
                $timestamp = $value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || $signatures === []) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        $matched = false;

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $matched = true;
            }
        }

        return $matched && abs(now()->getTimestamp() - (int) $timestamp) <= self::TOLERANCE_SECONDS;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailable
     */
    private function call(string $method, string $path, array $fields = []): array
    {
        $request = Http::withToken($this->setting('secret_key'));
        $url = $this->host().$path;

        return $method === 'post' ? $this->sendForm($request, $url, $fields) : $this->sendJson($request, 'get', $url);
    }

    private function host(): string
    {
        $host = rtrim($this->setting('host'), '/');

        return $host !== '' ? $host : self::HOST;
    }

    private function setting(string $name): string
    {
        return trim($this->settings[$name] ?? '');
    }
}
