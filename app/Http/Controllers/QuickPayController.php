<?php

namespace App\Http\Controllers;

use App\Actions\Payments\StartOnlinePayment;
use App\Exceptions\GatewayUnavailable;
use App\Exceptions\NothingToPay;
use App\Exceptions\TooManyPaymentAttempts;
use App\Models\Account;
use App\Models\OnlinePayment;
use App\Payments\PayCode;
use App\Payments\PaymentGateways;
use App\Payments\QuickPay;
use App\Payments\QuickPayQuote;
use App\Payments\UpiDetails;
use App\Support\Money;
use App\Support\PublicPage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

/**
 * The public pay page (quick pay): find what is due by username or mobile number, pay it through a gateway, see
 * how it went. Shows nobody's name, plan or details: only a masked username and amounts. Open only while quick pay,
 * invoicing and a gateway are on.
 */
class QuickPayController extends Controller
{
    /**
     * How long the Pay button of a search result stays valid.
     */
    private const TOKEN_MINUTES = 15;

    public function show(): View
    {
        abort_unless(QuickPay::isOpen(), 404);

        return $this->page('pay.find', ['who' => '', 'rows' => null]);
    }

    public function find(Request $request, QuickPay $quickPay): View
    {
        abort_unless(QuickPay::isOpen(), 404);
        $who = trim((string) $request->string('who'));
        $request->merge(['who' => $who])->validate(['who' => ['required', 'string', 'max:100']], [], ['who' => __('username or mobile number')]);

        return $this->page('pay.find', ['who' => $who, 'rows' => $this->rows($quickPay->find($who))]);
    }

    /**
     * The pay link of a reminder (/p/{code}): the search result for that one customer, nothing to type.
     */
    public function code(string $code, QuickPay $quickPay): View|RedirectResponse
    {
        abort_unless(QuickPay::isOpen(), 404);
        $accountId = PayCode::accountId($code);

        if ($accountId === null) {
            return redirect()->route('pay.show')->with('pay_message', __('That link is not valid. Search by username or mobile number.'));
        }

        $account = Account::withTrashed()->find($accountId);
        $quote = $account === null ? null : $quickPay->quote($account);

        return $this->page('pay.find', ['who' => '', 'rows' => $this->rows($quote === null ? [] : [$quote])]);
    }

    public function start(Request $request, StartOnlinePayment $start, PaymentGateways $gateways): View|RedirectResponse
    {
        abort_unless(QuickPay::isOpen(), 404);
        $request->validate(['token' => ['required', 'string', 'max:1000'], 'gateway' => ['required', 'string', 'in:'.implode(',', $gateways->enabled())]]);
        $accountId = $this->accountFrom((string) $request->string('token'));

        if ($accountId === null) {
            return redirect()->route('pay.show')->with('pay_message', __('That page was open too long. Search again.'));
        }

        $account = Account::query()->find($accountId);

        if ($account === null || ! in_array((string) $request->string('gateway'), $gateways->enabledFor($account), true)) {
            return redirect()->route('pay.show')->with('pay_message', __('That payment option is not available. Search again or call us.'));
        }

        try {
            $started = $start->handle($accountId, (string) $request->string('gateway'), $request->ip());
        } catch (NothingToPay) {
            return redirect()->route('pay.show')->with('pay_message', __('Nothing to pay for this username or number.'));
        } catch (TooManyPaymentAttempts) {
            return redirect()->route('pay.show')->with('pay_message', __('Too many tries. Wait an hour and try again, or call us.'));
        } catch (GatewayUnavailable $refused) {
            report($refused);

            return redirect()->route('pay.show')->with('pay_message', __('The payment could not be started. Try again in a few minutes.'));
        }

        return $started->start->kind === 'redirect'
            ? redirect()->away($started->start->url)
            : $this->page('pay.go', ['start' => $started->start]);
    }

    public function result(string $publicId): View
    {
        $payment = OnlinePayment::query()->where('public_id', $publicId)->firstOrFail();

        return $this->page('pay.result', ['payment' => $payment, 'receipts' => $payment->receiptNumbers(), 'state' => $payment->publicState()]);
    }

    /**
     * Search results for the page: a masked username, the amounts and a Pay token that expires.
     *
     * Each row also lists the gateways that customer can use and why another is hidden (it needs their mobile or
     * email), as "Label: message".
     *
     * @param  list<QuickPayQuote>  $quotes
     * @return list<array{username: string, dues: string, renewal: ?string, total: string, token: string, gateways: list<array{key: string, label: string}>, unavailable: list<string>}>
     */
    private function rows(array $quotes): array
    {
        $expires = now()->addMinutes(self::TOKEN_MINUTES)->getTimestamp();
        $gateways = app(PaymentGateways::class);
        $label = fn (string $key): string => ($gateways->class($key) ?? '')::label();

        return array_map(function (QuickPayQuote $quote) use ($expires, $gateways, $label): array {
            $account = Account::withTrashed()->find($quote->accountId);
            ['usable' => $usable, 'hidden' => $hidden] = $account === null ? ['usable' => [], 'hidden' => []] : $gateways->splitFor($account);

            return [
                'username' => $quote->maskedUsername,
                'dues' => $quote->dues,
                'renewal' => $quote->renewal?->price,
                'total' => $quote->total,
                'token' => Crypt::encryptString((string) json_encode(['a' => $quote->accountId, 'x' => $expires])),
                'gateways' => array_map(fn (string $key): array => ['key' => $key, 'label' => $label($key)], $usable),
                'unavailable' => array_map(fn (string $key, string $message): string => $label($key).': '.$message, array_keys($hidden), $hidden),
            ];
        }, $quotes);
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    private function page(string $view, array $data): View
    {
        $money = Money::fromSettings();

        return view($view, [...$data, ...PublicPage::data(), 'upi' => UpiDetails::current(), 'money' => fn (?string $amount): string => $money->format((string) $amount)]);
    }

    private function accountFrom(string $token): ?int
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($data) && is_int($data['a'] ?? null) && is_int($data['x'] ?? null) && $data['x'] >= now()->getTimestamp() ? $data['a'] : null;
    }
}
