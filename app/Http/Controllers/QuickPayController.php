<?php

namespace App\Http\Controllers;

use App\Actions\Payments\StartOnlinePayment;
use App\Billing\BillingSettings;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\GatewayUnavailable;
use App\Exceptions\NothingToPay;
use App\Exceptions\TooManyPaymentAttempts;
use App\Models\AppSetting;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Payments\PaymentGateways;
use App\Payments\QuickPay;
use App\Payments\QuickPayQuote;
use App\Support\Money;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
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
        $expires = now()->addMinutes(self::TOKEN_MINUTES)->getTimestamp();

        $rows = array_map(fn (QuickPayQuote $quote): array => [
            'username' => $quote->maskedUsername,
            'dues' => $quote->dues,
            'renewal' => $quote->renewal?->price,
            'total' => $quote->total,
            'token' => Crypt::encryptString((string) json_encode(['a' => $quote->accountId, 'x' => $expires])),
        ], $quickPay->find($who));

        return $this->page('pay.find', ['who' => $who, 'rows' => $rows]);
    }

    public function start(Request $request, StartOnlinePayment $start, PaymentGateways $gateways): View|RedirectResponse
    {
        abort_unless(QuickPay::isOpen(), 404);
        $request->validate(['token' => ['required', 'string', 'max:1000'], 'gateway' => ['required', 'string', 'in:'.implode(',', $gateways->enabled())]]);
        $accountId = $this->accountFrom((string) $request->string('token'));

        if ($accountId === null) {
            return redirect()->route('pay.show')->with('pay_message', __('That page was open too long. Search again.'));
        }

        try {
            $started = $start->handle($accountId, (string) $request->string('gateway'));
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
        $receipts = $payment->status === OnlinePaymentStatus::Paid
            ? Payment::query()->where('account_id', $payment->account_id)->where('reference', $payment->gateway_txn_id ?? $payment->public_id)
                ->whereHas('method', fn ($query) => $query->where('gateway', $payment->gateway))
                ->orderBy('id')->pluck('id')->map(fn (int $id): string => 'RCPT-'.$id)->all()
            : [];

        $state = match ($payment->status) {
            OnlinePaymentStatus::Paid, OnlinePaymentStatus::NeedsAttention => 'received',
            OnlinePaymentStatus::Pending => 'pending',
            OnlinePaymentStatus::Failed => 'failed',
        };

        return $this->page('pay.result', ['payment' => $payment, 'receipts' => $receipts, 'state' => $state]);
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    private function page(string $view, array $data): View
    {
        $settings = BillingSettings::current();
        $phone = trim((string) AppSetting::valueOf('notice_phone', ''));
        $money = Money::fromSettings();
        $disk = Storage::disk('billing');
        $logo = $settings->logo !== '' && $disk->exists($settings->logo)
            ? 'data:'.$disk->mimeType($settings->logo).';base64,'.base64_encode((string) $disk->get($settings->logo))
            : null;

        return view($view, [...$data, 'company' => $settings->company->name, 'logo' => $logo, 'phone' => $phone, 'phoneLink' => (string) preg_replace('/[^0-9+]/', '', $phone), 'money' => fn (?string $amount): string => $money->format((string) $amount),
            'gateways' => array_map(fn (string $key): array => ['key' => $key, 'label' => (app(PaymentGateways::class)->class($key) ?? '')::label()], app(PaymentGateways::class)->enabled())]);
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
