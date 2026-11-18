<?php

namespace App\Payments\Payu;

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
 * PayU India, hosted checkout (the customer's browser posts a signed form to PayU). Docs, checked 2026-11-18:
 * https://docs.payu.in/docs/generate-hash-merchant-hosted   (request hash, reverse hash, additional charges variant)
 * https://docs.payu.in/reference/verify_payment_api         (verify_payment on postservice, form=2)
 * https://docs.payu.in/docs/payu-hosted-integration-subscriptions (parameter table: txnid, character limit 25)
 *
 * The docs give the transaction id a limit of 25 characters and our public id has 26, so the id sent as txnid is the
 * public id without its first character (the attempt keeps it as gateway_order_id). The limits of firstname and the
 * other fields were not found in the pages above: firstname is sent as letters and spaces only, at most 60 characters,
 * which is the usual PayU limit, not a confirmed one.
 *
 * Nothing the browser or a webhook says is believed. The return (surl/furl) and the webhook carry a reverse hash
 * (sha512 over salt, status, the udf fields, email, firstname, productinfo, amount, txnid, key); a matching hash for
 * our own key only names an attempt, found through the txnid saved at start. Settling reads the transaction from PayU
 * (verify_payment); the amount comes from that answer. PayU India takes rupees only, so the currency of a success is
 * INR. The posted additionalCharges (a surcharge PayU adds to the reverse hash) is handled: it is put in front of the
 * hash string when present.
 */
final class PayuGateway implements NeedsCustomerDetails, PaymentGateway, UntestedGateway
{
    use CallsGatewayApi;

    private const TEST_HOST = 'https://test.payu.in';

    private const LIVE_HOST = 'https://secure.payu.in';

    private const LIVE_INFO_HOST = 'https://info.payu.in';

    private const TXN_LIMIT = 25;

    private const PRODUCT = 'Internet bill';

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'payu';
    }

    public static function label(): string
    {
        return 'PayU';
    }

    public static function currencies(): array
    {
        return ['INR'];
    }

    public static function requiredDetails(): array
    {
        return ['phone', 'email'];
    }

    public static function settingFields(): array
    {
        return [
            new SettingField('environment', __('Environment'), help: __('test (PayU test server) or live.'), maxLength: 4, rules: ['in:test,live']),
            new SettingField('merchant_key', __('Merchant key'), maxLength: 100),
            new SettingField('merchant_salt', __('Merchant salt'), 'secret', help: __('Signs the payment and checks the answers.'), maxLength: 200),
            // https only: the replies decide whether a payment counts as paid.
            new SettingField('host', __('PayU payment address'), 'url', required: false, help: __('Empty: the address PayU documents. Set it only if PayU moves.'), maxLength: 200, rules: ['url:https']),
            new SettingField('info_host', __('PayU status address'), 'url', required: false, help: __('Empty: the address PayU documents for status checks. Set it only if PayU moves.'), maxLength: 200, rules: ['url:https']),
        ];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        $details = CustomerDetails::for($payment->account);
        $phone = $details->nationalPhone();

        if ($phone === null || $details->email === null) {
            throw new GatewayUnavailable('PayU needs the customer mobile number and email.');
        }

        $txnid = $this->txnid($payment->public_id);
        $amount = GatewayAmount::twoDecimals((string) $payment->amount);
        $firstname = $this->firstname($details->name);
        $hash = hash('sha512', implode('|', [$this->setting('merchant_key'), $txnid, $amount, self::PRODUCT, $firstname, $details->email, $payment->public_id,
            '', '', '', '', '', '', '', '', '', $this->setting('merchant_salt')]));

        return GatewayStart::form($this->host().'/_payment', [
            'key' => $this->setting('merchant_key'),
            'txnid' => $txnid,
            'amount' => $amount,
            'productinfo' => self::PRODUCT,
            'firstname' => $firstname,
            'email' => $details->email,
            'phone' => $phone,
            'surl' => $returnUrl,
            'furl' => $returnUrl,
            'udf1' => $payment->public_id,
            'hash' => $hash,
        ], $txnid);
    }

    /**
     * Pending whenever PayU cannot be reached or does not report the transaction as final.
     */
    public function status(OnlinePayment $payment): GatewayResult
    {
        $txnid = $payment->gateway_order_id;

        if ($txnid === null || $txnid === '') {
            return new GatewayResult(GatewayStatus::Pending);
        }

        try {
            $answer = $this->sendForm(Http::asForm(), $this->infoHost().'/merchant/postservice?form=2', [
                'key' => $this->setting('merchant_key'),
                'command' => 'verify_payment',
                'var1' => $txnid,
                'hash' => hash('sha512', $this->setting('merchant_key').'|verify_payment|'.$txnid.'|'.$this->setting('merchant_salt')),
            ]);
        } catch (GatewayUnavailable) {
            return new GatewayResult(GatewayStatus::Pending);
        }

        $details = $answer['transaction_details'] ?? null;
        $entry = is_array($details) && is_array($details[$txnid] ?? null) ? $details[$txnid] : null;
        $state = is_array($entry) && is_string($entry['status'] ?? null) ? $entry['status'] : null;

        if ($entry === null || ! is_scalar($answer['status'] ?? null) || (string) $answer['status'] !== '1' || (isset($entry['txnid']) && $entry['txnid'] !== $txnid)) {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $state, raw: $entry === null ? [] : $this->safeRaw($entry));
        }

        $raw = $this->safeRaw($entry);

        // A failed try is not final: PayU lets the customer retry the same txnid, and only pending attempts are re-checked.
        if ($state !== 'success') {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $state, raw: $raw);
        }

        $amount = $entry['amt'] ?? null;

        try {
            $converted = match (true) {
                is_string($amount), is_int($amount) => GatewayAmount::twoDecimals((string) $amount),
                is_float($amount) => GatewayAmount::twoDecimals((string) json_encode($amount)),
                default => null,
            };
        } catch (GatewayUnavailable) {
            $converted = null;
        }

        if ($converted === null) {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $state, raw: $raw);
        }

        $mihpayid = $entry['mihpayid'] ?? null;

        return new GatewayResult(
            status: GatewayStatus::Paid,
            amount: $converted,
            txnId: is_string($mihpayid) && $mihpayid !== '' ? $mihpayid : null,
            providerStatus: $state,
            raw: $raw,
            currency: 'INR',
        );
    }

    /**
     * Only names an attempt. A POST (the return or the webhook) must carry PayU's reverse hash for our key, computed
     * with our salt, over the posted fields, and a txnid saved on an attempt of this gateway. A plain GET (a customer
     * who came back without a post) is looked up by our own `attempt` parameter so it can be re-checked.
     */
    public function verify(Request $request): ?string
    {
        if (! $request->isMethod('POST')) {
            $attempt = $request->query('attempt');

            return is_string($attempt) && $attempt !== '' && OnlinePayment::query()->where('public_id', $attempt)->where('gateway', self::key())->exists() ? $attempt : null;
        }

        $salt = $this->setting('merchant_salt');
        $key = $this->setting('merchant_key');
        $post = [];

        foreach (['hash', 'key', 'txnid', 'status', 'amount', 'productinfo', 'firstname', 'email', 'udf1', 'udf2', 'udf3', 'udf4', 'udf5', 'additionalCharges', 'additional_charges'] as $name) {
            // The framework turns an empty posted field into null; PayU hashed it as an empty string.
            $value = $request->input($name) ?? '';

            if (! is_string($value)) {
                return null;
            }

            $post[$name] = $value;
        }

        if ($salt === '' || $key === '' || $post['hash'] === '' || $post['txnid'] === '' || ! hash_equals($key, $post['key'])) {
            return null;
        }

        $charges = $post['additionalCharges'] !== '' ? $post['additionalCharges'] : $post['additional_charges'];
        $string = ($charges !== '' ? $charges.'|' : '').$salt.'|'.$post['status'].'||||||'.implode('|', [$post['udf5'], $post['udf4'], $post['udf3'], $post['udf2'], $post['udf1'],
            $post['email'], $post['firstname'], $post['productinfo'], $post['amount'], $post['txnid'], $post['key']]);

        if (! hash_equals(hash('sha512', $string), strtolower(trim($post['hash'])))) {
            return null;
        }

        $found = OnlinePayment::query()->where('gateway_order_id', $post['txnid'])->where('gateway', self::key())->value('public_id');

        return is_string($found) ? $found : null;
    }

    private function txnid(string $publicId): string
    {
        return strlen($publicId) > self::TXN_LIMIT ? substr($publicId, strlen($publicId) - self::TXN_LIMIT) : $publicId;
    }

    /**
     * Letters, combining marks and spaces only (PayU rejects other characters in a name), at most 60 characters.
     */
    private function firstname(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{M} ]+/u', ' ', $name)));

        return mb_substr($clean !== '' ? $clean : 'Customer', 0, 60);
    }

    /**
     * What is kept on the attempt: ids, states and the amount, never the card, bank, UPI or customer details.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function safeRaw(array $entry): array
    {
        return array_intersect_key($entry, array_flip(['mihpayid', 'txnid', 'status', 'amt', 'mode', 'unmappedstatus', 'addedon', 'error_code', 'error_Message']));
    }

    private function host(): string
    {
        $host = rtrim($this->setting('host'), '/');

        if ($host !== '') {
            return $host;
        }

        return $this->setting('environment') === 'live' ? self::LIVE_HOST : self::TEST_HOST;
    }

    private function infoHost(): string
    {
        $host = rtrim($this->setting('info_host'), '/');

        if ($host !== '') {
            return $host;
        }

        return $this->setting('environment') === 'live' ? self::LIVE_INFO_HOST : self::TEST_HOST;
    }

    private function setting(string $name): string
    {
        return trim($this->settings[$name] ?? '');
    }
}
