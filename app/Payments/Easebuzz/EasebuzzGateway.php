<?php

namespace App\Payments\Easebuzz;

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
 * Easebuzz hosted checkout (an access key is created server to server, then the customer is sent to Easebuzz's page).
 * Sources, checked 2026-11-18:
 * https://docs.easebuzz.in/docs/payment-gateway/8ec545c331e6f-initiate-payment-api  (Initiate Payment API)
 * https://docs.easebuzz.in/docs/payment-gateway/paw9n1qc3kuoz-transaction-webhook   (Transaction Webhook)
 * https://github.com/easebuzz/paywitheasebuzz-php-lib  (Easebuzz's own PHP library: easebuzz-lib/initiate_payment.php,
 *   transaction.php, utils.php and response.php)
 *
 * The docs site could not be read by the tool used for this task (the pages answered 404 to it), so the facts below come
 * from Easebuzz's own PHP library, which is not the same as reading the reference pages. They need one look against the
 * live reference and a test account:
 * - initiate: POST {pay host}/payment/initiateLink, form encoded, hash sha512(key|txnid|amount|productinfo|firstname|email|
 *   udf1..udf10|salt); the answer is {"status": 1, "data": "<64 hex access key>"}; the customer goes to {pay host}/pay/{key}.
 *   Hosts: https://testpay.easebuzz.in and https://pay.easebuzz.in.
 * - status: POST {dashboard host}/transaction/v2/retrieve, form encoded, key, txnid and hash sha512(key|txnid|salt).
 *   Hosts: https://testdashboard.easebuzz.in and https://dashboard.easebuzz.in. The library does not show the shape of the
 *   answer, so it is read forgivingly: the transaction(s) are looked for in `data` (or `msg`), as one object or a list, and
 *   only an entry whose txnid is ours counts. If several are listed a success wins.
 * - return (surl/furl, POST) reverse hash: sha512(salt|status|udf10..udf1|email|firstname|productinfo|amount|txnid|key).
 * - the library limits: txnid up to 40 of letters, digits and _ | - /, so our 26 character public id is sent as is;
 *   productinfo up to 45 of letters, digits, spaces and - |; amounts need a decimal point and at least 1.00. The library
 *   allows only ASCII in firstname; that limit is not confirmed by the reference, so letters and marks are kept.
 *
 * Nothing the browser or a webhook says is believed. A POST (return or webhook) names an attempt only when its reverse
 * hash is right for our key and salt and its txnid is an attempt of this gateway. Whether Easebuzz signs its webhook the
 * same way as the return could not be confirmed; a webhook that does not carry a matching hash is simply refused, which
 * is safe, because the return and the periodic re-check settle the payment through status(), the only thing that does.
 * Easebuzz takes rupees only, so a success is in INR.
 */
final class EasebuzzGateway implements NeedsCustomerDetails, PaymentGateway, UntestedGateway
{
    use CallsGatewayApi;

    private const TEST_HOST = 'https://testpay.easebuzz.in';

    private const LIVE_HOST = 'https://pay.easebuzz.in';

    private const TEST_DASHBOARD = 'https://testdashboard.easebuzz.in';

    private const LIVE_DASHBOARD = 'https://dashboard.easebuzz.in';

    private const PRODUCT = 'Internet bill';

    private const UDF_COUNT = 10;

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'easebuzz';
    }

    public static function label(): string
    {
        return 'Easebuzz';
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
            new SettingField('environment', __('Environment'), help: __('test (Easebuzz test server) or live.'), maxLength: 4, rules: ['in:test,live']),
            new SettingField('merchant_key', __('Merchant key'), maxLength: 100),
            new SettingField('salt', __('Salt'), 'secret', help: __('Signs the payment and checks the answers.'), maxLength: 200),
            // https only: the replies decide whether a payment counts as paid.
            new SettingField('host', __('Easebuzz payment address'), 'url', required: false, help: __('Empty: the address Easebuzz documents. Set it only if Easebuzz moves.'), maxLength: 200, rules: ['url:https']),
            new SettingField('dashboard_host', __('Easebuzz status address'), 'url', required: false, help: __('Empty: the address Easebuzz documents for status checks. Set it only if Easebuzz moves.'), maxLength: 200, rules: ['url:https']),
        ];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        $details = CustomerDetails::for($payment->account);
        $phone = $details->nationalPhone();

        if ($phone === null || $details->email === null) {
            throw new GatewayUnavailable('Easebuzz needs the customer mobile number and email.');
        }

        $txnid = $payment->public_id;
        $amount = GatewayAmount::twoDecimals((string) $payment->amount);
        $firstname = $this->firstname($details->name);
        $hash = hash('sha512', implode('|', [$this->setting('merchant_key'), $txnid, $amount, self::PRODUCT, $firstname, $details->email,
            ...array_fill(0, self::UDF_COUNT, ''), $this->setting('salt')]));

        $answer = $this->sendForm(Http::asForm(), $this->host().'/payment/initiateLink', [
            'key' => $this->setting('merchant_key'),
            'txnid' => $txnid,
            'amount' => $amount,
            'productinfo' => self::PRODUCT,
            'firstname' => $firstname,
            'phone' => $phone,
            'email' => $details->email,
            'surl' => $returnUrl,
            'furl' => $returnUrl,
            'hash' => $hash,
        ]);

        $access = $answer['data'] ?? null;

        if (! $this->isSuccessFlag($answer['status'] ?? null) || ! is_string($access) || preg_match('/^[A-Za-z0-9]{16,128}$/', $access) !== 1) {
            // Never the body: it can carry the merchant's details.
            throw new GatewayUnavailable('Easebuzz did not start the payment.');
        }

        return GatewayStart::redirect($this->host().'/pay/'.$access, $txnid);
    }

    /**
     * Pending whenever Easebuzz cannot be reached or does not report the transaction as a success. A failure,
     * cancellation or drop is not final: the customer can try again on the same transaction id, and only pending
     * attempts are re-checked (a failed one would be closed after a day and a later real payment lost).
     */
    public function status(OnlinePayment $payment): GatewayResult
    {
        $txnid = $payment->public_id;

        try {
            $answer = $this->sendForm(Http::asForm(), $this->dashboardHost().'/transaction/v2/retrieve', [
                'txnid' => $txnid,
                'key' => $this->setting('merchant_key'),
                'hash' => hash('sha512', $this->setting('merchant_key').'|'.$txnid.'|'.$this->setting('salt')),
            ]);
        } catch (GatewayUnavailable) {
            return new GatewayResult(GatewayStatus::Pending);
        }

        $entry = $this->isSuccessFlag($answer['status'] ?? null) ? $this->entryFor($answer, $txnid) : null;

        if ($entry === null) {
            return new GatewayResult(GatewayStatus::Pending);
        }

        $state = is_string($entry['status'] ?? null) ? $entry['status'] : null;
        $raw = $this->safeRaw($entry);

        if ($state === null || strtolower($state) !== 'success') {
            return new GatewayResult(GatewayStatus::Pending, providerStatus: $state, raw: $raw);
        }

        $amount = $entry['amount'] ?? null;

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

        $easepayid = $entry['easepayid'] ?? null;

        return new GatewayResult(
            status: GatewayStatus::Paid,
            amount: $converted,
            txnId: is_string($easepayid) && $easepayid !== '' ? $easepayid : null,
            providerStatus: $state,
            raw: $raw,
            // Easebuzz settles in rupees only.
            currency: 'INR',
        );
    }

    /**
     * Only names an attempt. A POST (the return or a webhook) must carry Easebuzz's reverse hash for our key, computed
     * with our salt, over the posted fields, and a txnid that is an attempt of this gateway. A plain GET (a customer who
     * came back without a post) is looked up by our own `attempt` parameter so it can be re-checked.
     */
    public function verify(Request $request): ?string
    {
        if (! $request->isMethod('POST')) {
            $attempt = $request->query('attempt');

            return is_string($attempt) && $attempt !== '' && OnlinePayment::query()->where('public_id', $attempt)->where('gateway', self::key())->exists() ? $attempt : null;
        }

        $salt = $this->setting('salt');
        $key = $this->setting('merchant_key');
        $names = ['hash', 'key', 'txnid', 'status', 'amount', 'productinfo', 'firstname', 'email', ...array_map(fn (int $n): string => 'udf'.$n, range(1, self::UDF_COUNT))];
        $post = $this->postedFields($request, $names);

        if ($post === null) {
            return null;
        }

        if ($salt === '' || $key === '' || $post['hash'] === '' || $post['txnid'] === '' || ! hash_equals($key, $post['key'])) {
            return null;
        }

        $parts = [$salt, $post['status']];

        foreach (range(self::UDF_COUNT, 1) as $n) {
            $parts[] = $post['udf'.$n];
        }

        array_push($parts, $post['email'], $post['firstname'], $post['productinfo'], $post['amount'], $post['txnid'], $post['key']);

        if (! hash_equals(hash('sha512', implode('|', $parts)), strtolower(trim($post['hash'])))) {
            return null;
        }

        $found = OnlinePayment::query()->where('public_id', $post['txnid'])->where('gateway', self::key())->value('public_id');

        return is_string($found) ? $found : null;
    }

    /**
     * Every wanted field as a string; an absent or empty one is '' (the framework turns an empty posted field into
     * null, while Easebuzz hashed it as an empty string). Null when any of them is not plain text.
     *
     * @param  list<string>  $names
     * @return array<string, string>|null
     */
    private function postedFields(Request $request, array $names): ?array
    {
        $post = [];

        foreach ($names as $name) {
            $value = $request->input($name) ?? '';

            if (! is_string($value)) {
                return null;
            }

            $post[$name] = $value;
        }

        return $post;
    }

    /**
     * The retrieve answer lists the transaction under `data` (or `msg`), as one object or a list. Only entries for our
     * txnid count; a success among them wins, otherwise the first one is returned.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>|null
     */
    private function entryFor(array $answer, string $txnid): ?array
    {
        $body = is_array($answer['data'] ?? null) ? $answer['data'] : (is_array($answer['msg'] ?? null) ? $answer['msg'] : []);
        $candidates = array_key_exists('txnid', $body) || array_key_exists('status', $body) ? [$body] : array_values($body);
        $mine = [];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && ($candidate['txnid'] ?? null) === $txnid) {
                $mine[] = $candidate;
            }
        }

        foreach ($mine as $candidate) {
            if (is_string($candidate['status'] ?? null) && strtolower($candidate['status']) === 'success') {
                return $candidate;
            }
        }

        return $mine[0] ?? null;
    }

    private function isSuccessFlag(mixed $flag): bool
    {
        return $flag === true || $flag === 1 || $flag === '1';
    }

    /**
     * Letters, combining marks and spaces only, at most 60 characters.
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
        return array_intersect_key($entry, array_flip(['easepayid', 'txnid', 'status', 'amount', 'mode', 'bank_ref_num', 'addedon', 'error_code']));
    }

    private function host(): string
    {
        $host = rtrim($this->setting('host'), '/');

        if ($host !== '') {
            return $host;
        }

        return $this->setting('environment') === 'live' ? self::LIVE_HOST : self::TEST_HOST;
    }

    private function dashboardHost(): string
    {
        $host = rtrim($this->setting('dashboard_host'), '/');

        if ($host !== '') {
            return $host;
        }

        return $this->setting('environment') === 'live' ? self::LIVE_DASHBOARD : self::TEST_DASHBOARD;
    }

    private function setting(string $name): string
    {
        return trim($this->settings[$name] ?? '');
    }
}
