<?php

namespace App\Payments\Cashfree;

use App\Contracts\NeedsCustomerDetails;
use App\Contracts\PaymentGateway;
use App\Contracts\UntestedGateway;
use App\Exceptions\GatewayUnavailable;
use App\Models\OnlinePayment;
use App\Notifications\SettingField;
use App\Payments\Concerns\CallsGatewayApi;
use App\Payments\CustomerDetails;
use App\Payments\GatewayAmount;
use App\Payments\GatewayResult;
use App\Payments\GatewayStart;
use App\Payments\GatewayStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Cashfree Payments, with Cashfree's JS SDK v3 (the browser opens checkout with a payment session id). Docs,
 * checked 2026-11-18:
 * https://www.cashfree.com/docs/api-reference/payments/latest/orders/create
 * https://www.cashfree.com/docs/api-reference/payments/latest/orders/get
 * https://www.cashfree.com/docs/payments/online/webhooks/signature-verification
 *
 * The webhook format follows Cashfree's PHP sample: base64 of HMAC-SHA256 over timestamp . rawBody (no separator) with
 * the client secret.
 *
 * The docs list 2026-01-01 as the newest x-api-version; this class stays on the documented 2025-01-01 contract it
 * was written against (API_VERSION), so a newer response shape cannot change what counts as paid.
 *
 * Nothing the browser or a webhook says is believed. The return carries no signature, so it only names an attempt
 * through our own `attempt` parameter; the webhook signature (base64 HMAC-SHA256 of timestamp + raw body with the
 * client secret) only names an attempt too. Settling reads the order from Cashfree (GET /pg/orders/{order_id});
 * the amount and currency come from that answer.
 */
final class CashfreeGateway implements NeedsCustomerDetails, PaymentGateway, UntestedGateway
{
    use CallsGatewayApi;

    private const API_VERSION = '2025-01-01';

    private const SANDBOX = 'https://sandbox.cashfree.com';

    private const LIVE = 'https://api.cashfree.com';

    private const SDK = 'https://sdk.cashfree.com/js/v3/cashfree.js';

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'cashfree';
    }

    public static function label(): string
    {
        return 'Cashfree';
    }

    public static function currencies(): array
    {
        return ['INR'];
    }

    /** Cashfree requires the customer's phone; its email is optional, so none is demanded or invented. */
    public static function requiredDetails(): array
    {
        return ['phone'];
    }

    public static function settingFields(): array
    {
        return [
            new SettingField('environment', __('Environment'), help: __('test (Cashfree sandbox) or live.'), maxLength: 4, rules: ['in:test,live']),
            new SettingField('client_id', __('Client ID'), maxLength: 100),
            new SettingField('client_secret', __('Client secret'), 'secret', help: __('Also signs the webhooks.'), maxLength: 200),
            // https only: the replies decide whether a payment counts as paid.
            new SettingField('host', __('Cashfree address'), 'url', required: false, help: __('Empty: the address Cashfree documents. Set it only if Cashfree moves.'), maxLength: 200, rules: ['url:https']),
        ];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        $details = CustomerDetails::for($payment->account);
        $phone = $details->nationalPhone();

        if ($phone === null) {
            throw new GatewayUnavailable('Cashfree needs the customer mobile number.');
        }

        $customer = ['customer_id' => 'CUST'.$payment->account_id, 'customer_phone' => $phone];
        $nameLength = mb_strlen($details->name);

        if ($nameLength >= 3 && $nameLength <= 100) {
            $customer['customer_name'] = $details->name;
        }

        if ($details->email !== null) {
            $customer['customer_email'] = $details->email;
        }

        $order = $this->json('post', '/pg/orders', [
            'order_id' => $payment->public_id,
            'order_amount' => (float) GatewayAmount::twoDecimals((string) $payment->amount),
            'order_currency' => $payment->currency,
            'customer_details' => $customer,
            'order_meta' => ['return_url' => $returnUrl],
        ]);

        $orderId = $order['order_id'] ?? null;
        $session = $order['payment_session_id'] ?? null;

        if (! is_string($orderId) || $orderId === '' || ! is_string($session) || $session === '') {
            throw new GatewayUnavailable('Cashfree answered without an order id or payment session.');
        }

        return GatewayStart::script(self::SDK, [
            'init' => 'cashfree',
            'mode' => $this->setting('environment') === 'live' ? 'production' : 'sandbox',
            'session' => $session,
        ], $orderId);
    }

    /**
     * Pending whenever Cashfree cannot be reached or the order is not in a final state.
     */
    public function status(OnlinePayment $payment): GatewayResult
    {
        try {
            $order = $this->json('get', '/pg/orders/'.rawurlencode($payment->public_id));
        } catch (GatewayUnavailable) {
            return new GatewayResult(GatewayStatus::Pending);
        }

        $state = is_string($order['order_status'] ?? null) ? $order['order_status'] : null;
        $raw = array_intersect_key($order, array_flip(['order_id', 'cf_order_id', 'order_status', 'order_amount', 'order_currency']));

        if (($order['order_id'] ?? null) !== $payment->public_id) {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $state, raw: $raw);
        }

        if ($state === 'EXPIRED' || $state === 'TERMINATED') {
            return new GatewayResult(GatewayStatus::Failed, providerStatus: $state, raw: $raw);
        }

        if ($state !== 'PAID') {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $state, raw: $raw);
        }

        $amount = $order['order_amount'] ?? null;
        $currency = $order['order_currency'] ?? null;

        try {
            $text = is_float($amount) ? json_encode($amount) : (is_int($amount) || is_string($amount) ? (string) $amount : null);
            $converted = is_string($text) ? GatewayAmount::twoDecimals($text) : null;
        } catch (GatewayUnavailable) {
            $converted = null;
        }

        if ($converted === null || ! is_string($currency) || $currency === '') {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $state, raw: $raw);
        }

        $txn = $order['cf_order_id'] ?? null;

        return new GatewayResult(
            status: GatewayStatus::Paid,
            amount: $converted,
            txnId: is_scalar($txn) ? (string) $txn : null,
            providerStatus: $state,
            raw: $raw,
            currency: $currency,
        );
    }

    /**
     * Only names an attempt. The return has no signature, so it is looked up by `attempt`. The webhook must carry a
     * valid base64 HMAC-SHA256 of timestamp + raw body, and names the order in data.order.order_id.
     */
    public function verify(Request $request): ?string
    {
        if ($request->is('pay/webhook/*')) {
            $secret = $this->setting('client_secret');
            $signature = $request->header('x-webhook-signature');
            $timestamp = $request->header('x-webhook-timestamp');

            if ($secret === '' || ! is_string($signature) || $signature === '' || ! is_string($timestamp) || $timestamp === ''
                || ! hash_equals(base64_encode(hash_hmac('sha256', $timestamp.$request->getContent(), $secret, true)), trim($signature))) {
                return null;
            }

            $order = $request->json('data.order.order_id');

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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailable
     */
    private function json(string $method, string $path, array $payload = []): array
    {
        $request = Http::withHeaders([
            'x-api-version' => self::API_VERSION,
            'x-client-id' => $this->setting('client_id'),
            'x-client-secret' => $this->setting('client_secret'),
        ]);

        return $this->sendJson($request, $method, $this->host().$path, $payload);
    }

    private function host(): string
    {
        $host = rtrim($this->setting('host'), '/');

        if ($host !== '') {
            return $host;
        }

        return $this->setting('environment') === 'live' ? self::LIVE : self::SANDBOX;
    }

    private function setting(string $name): string
    {
        return trim($this->settings[$name] ?? '');
    }
}
