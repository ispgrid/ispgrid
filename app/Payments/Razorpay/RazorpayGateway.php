<?php

namespace App\Payments\Razorpay;

use App\Billing\BillingSettings;
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
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay, hosted checkout (the customer's browser posts a form to Razorpay). Docs, checked 2026-11-18:
 * https://razorpay.com/docs/api/orders/create/
 * https://razorpay.com/docs/api/orders/fetch-payments/
 * https://razorpay.com/docs/api/payments/capture/
 * https://razorpay.com/docs/payments/payment-gateway/web-integration/hosted/build-integration/
 * https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/build-integration/#14-verify-payment-signature
 * https://razorpay.com/docs/webhooks/validate-test/
 *
 * Nothing the browser or a webhook says is believed. The return signature (HMAC-SHA256 of "order_id|payment_id" with
 * the key secret) and the webhook signature (HMAC-SHA256 of the raw body with the webhook secret) only name an
 * attempt, matched through the Razorpay order id saved at start. Settling then reads the order's payments from
 * Razorpay; the amount and currency come from that answer.
 */
final class RazorpayGateway implements NeedsCustomerDetails, PaymentGateway, UntestedGateway
{
    use CallsGatewayApi;

    private const HOST = 'https://api.razorpay.com';

    private const CHECKOUT = 'https://api.razorpay.com/v1/checkout/embedded';

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'razorpay';
    }

    public static function label(): string
    {
        return 'Razorpay';
    }

    public static function currencies(): array
    {
        return ['INR'];
    }

    public static function requiredDetails(): array
    {
        return ['phone'];
    }

    public static function settingFields(): array
    {
        return [
            new SettingField('environment', __('Environment'), help: __('test or live. Razorpay keys carry their own mode, this only labels it.'), maxLength: 4, rules: ['in:test,live']),
            new SettingField('key_id', __('Key ID'), maxLength: 100),
            new SettingField('key_secret', __('Key secret'), 'secret', maxLength: 200),
            new SettingField('webhook_secret', __('Webhook secret'), 'secret', help: __('The secret you set for the webhook in the Razorpay dashboard.'), maxLength: 200),
            // https only: the replies decide whether a payment counts as paid.
            new SettingField('host', __('Razorpay address'), 'url', required: false, help: __('Empty: the address Razorpay documents. Set it only if Razorpay moves.'), maxLength: 200, rules: ['url:https']),
        ];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        $details = CustomerDetails::for($payment->account);
        $minor = GatewayAmount::minor((string) $payment->amount);

        $order = $this->json('post', '/v1/orders', ['amount' => $minor, 'currency' => $payment->currency, 'receipt' => $payment->public_id]);
        $orderId = $order['id'] ?? null;

        if (! is_string($orderId) || $orderId === '') {
            throw new GatewayUnavailable('Razorpay answered without an order id.');
        }

        $company = trim(BillingSettings::current()->company->name);
        $fields = [
            'key_id' => $this->setting('key_id'),
            'order_id' => $orderId,
            'name' => $company !== '' ? $company : (string) config('app.name'),
            'prefill[name]' => $details->name,
            'prefill[contact]' => (string) $details->phone,
        ];

        if ($details->email !== null) {
            $fields['prefill[email]'] = $details->email;
        }

        $fields['callback_url'] = $returnUrl;
        $fields['cancel_url'] = $returnUrl;

        return GatewayStart::form(self::CHECKOUT, $fields, $orderId);
    }

    /**
     * Pending whenever Razorpay cannot be reached or the payments are not final: Razorpay lets the customer retry
     * inside one order, so Failed is never decided here (the daily check fails stale attempts).
     */
    public function status(OnlinePayment $payment): GatewayResult
    {
        $orderId = $payment->gateway_order_id;

        if ($orderId === null || $orderId === '') {
            return new GatewayResult(GatewayStatus::Pending);
        }

        try {
            $items = $this->paymentsOf($orderId);
            $captured = $this->firstWithStatus($items, 'captured');
            $authorized = $captured === null ? $this->firstWithStatus($items, 'authorized') : null;

            if ($authorized !== null) {
                $captured = $this->capture($authorized, $orderId, $payment);
            }
        } catch (GatewayUnavailable) {
            return new GatewayResult(GatewayStatus::Pending);
        }

        if ($captured !== null) {
            return $this->paid($captured);
        }

        $last = $items === [] ? null : $items[array_key_last($items)];

        return new GatewayResult(GatewayStatus::Pending, providerStatus: is_array($last) && is_string($last['status'] ?? null) ? $last['status'] : null,
            raw: ['payments' => array_map($this->safeRaw(...), $items)]);
    }

    /**
     * Only names an attempt. A return carrying the signature fields must carry all three and a valid signature for an
     * order id saved on an attempt of this gateway. A cancelled return has none of them and is looked up by the
     * `attempt` parameter only so it can be re-checked. The webhook must carry a valid signature of the raw body.
     */
    public function verify(Request $request): ?string
    {
        if ($request->is('pay/webhook/*')) {
            $secret = $this->setting('webhook_secret');
            $header = $request->header('X-Razorpay-Signature');

            if ($secret === '' || ! is_string($header) || $header === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), strtolower(trim($header)))) {
                return null;
            }

            $order = $request->json('payload.payment.entity.order_id');

            return is_string($order) ? $this->attemptByOrder($order) : null;
        }

        $order = $request->input('razorpay_order_id');
        $paymentId = $request->input('razorpay_payment_id');
        $signature = $request->input('razorpay_signature');

        if ($order === null && $paymentId === null && $signature === null) {
            $attempt = $request->query('attempt');

            return is_string($attempt) && $attempt !== '' ? $this->attemptByPublicId($attempt) : null;
        }

        $secret = $this->setting('key_secret');

        if ($secret === '' || ! is_string($order) || ! is_string($paymentId) || ! is_string($signature) || $order === '' || $paymentId === '' || $signature === ''
            || ! hash_equals(hash_hmac('sha256', $order.'|'.$paymentId, $secret), strtolower(trim($signature)))) {
            return null;
        }

        return $this->attemptByOrder($order);
    }

    private function attemptByOrder(string $orderId): ?string
    {
        $found = $orderId === '' ? null : OnlinePayment::query()->where('gateway_order_id', $orderId)->where('gateway', self::key())->value('public_id');

        return is_string($found) ? $found : null;
    }

    private function attemptByPublicId(string $publicId): ?string
    {
        return OnlinePayment::query()->where('public_id', $publicId)->where('gateway', self::key())->exists() ? $publicId : null;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws GatewayUnavailable
     */
    private function paymentsOf(string $orderId): array
    {
        $answer = $this->json('get', '/v1/orders/'.rawurlencode($orderId).'/payments');
        $items = [];

        foreach (is_array($answer['items'] ?? null) ? $answer['items'] : [] as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && ($item['order_id'] ?? $orderId) === $orderId) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function firstWithStatus(array $items, string $status): ?array
    {
        foreach ($items as $item) {
            if (($item['status'] ?? null) === $status) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Captures an authorized payment, once, only when its amount and currency equal Razorpay's own order and the
     * attempt. An error (an "already captured" one included) re-reads the payments instead of trusting anything.
     *
     * @param  array<string, mixed>  $authorized
     * @return array<string, mixed>|null the captured payment, or null when none is captured
     *
     * @throws GatewayUnavailable
     */
    private function capture(array $authorized, string $orderId, OnlinePayment $payment): ?array
    {
        $order = $this->json('get', '/v1/orders/'.rawurlencode($orderId));
        $minor = GatewayAmount::minor((string) $payment->amount);

        if (($order['amount'] ?? null) !== $minor || ($order['currency'] ?? null) !== $payment->currency
            || ($authorized['amount'] ?? null) !== $minor || ($authorized['currency'] ?? null) !== $payment->currency) {
            return null;
        }

        try {
            $answer = $this->json('post', '/v1/payments/'.rawurlencode((string) $authorized['id']).'/capture', ['amount' => $minor, 'currency' => $payment->currency]);

            if (($answer['status'] ?? null) === 'captured') {
                return $answer;
            }
        } catch (GatewayUnavailable) {
            // fall through to the re-read
        }

        return $this->firstWithStatus($this->paymentsOf($orderId), 'captured');
    }

    /**
     * @param  array<string, mixed>  $captured
     */
    private function paid(array $captured): GatewayResult
    {
        $amount = $captured['amount'] ?? null;
        $currency = $captured['currency'] ?? null;

        try {
            $converted = is_int($amount) || (is_string($amount) && ctype_digit($amount)) ? GatewayAmount::fromMinor($amount) : null;
        } catch (GatewayUnavailable) {
            $converted = null;
        }

        if ($converted === null || ! is_string($currency) || $currency === '') {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: 'captured', raw: $this->safeRaw($captured));
        }

        return new GatewayResult(
            status: GatewayStatus::Paid,
            amount: $converted,
            txnId: is_string($captured['id'] ?? null) ? $captured['id'] : null,
            paidAt: is_int($captured['created_at'] ?? null) ? CarbonImmutable::createFromTimestamp($captured['created_at']) : null,
            providerStatus: 'captured',
            raw: $this->safeRaw($captured),
            currency: $currency,
        );
    }

    /**
     * What is kept on the attempt: ids, states and amounts, never the card, bank, UPI or customer details.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private function safeRaw(array $payment): array
    {
        return array_intersect_key($payment, array_flip(['id', 'order_id', 'status', 'amount', 'currency', 'method', 'captured', 'created_at', 'error_code', 'error_reason']));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailable
     */
    private function json(string $method, string $path, array $payload = []): array
    {
        $request = Http::withBasicAuth($this->setting('key_id'), $this->setting('key_secret'));

        return $this->sendJson($request, $method, $this->host().$path, $payload);
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
