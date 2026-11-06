<?php

namespace App\Actions\Payments;

use App\Actions\Customers\RechargeAccount;
use App\Billing\BillingSettings;
use App\Billing\Decimal;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\GatewayUnavailable;
use App\Exceptions\NothingToPay;
use App\Exceptions\TooManyPaymentAttempts;
use App\Models\Account;
use App\Models\OnlinePayment;
use App\Payments\GatewayStart;
use App\Payments\PaymentGateways;
use App\Payments\QuickPay;
use App\Payments\StartedPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens a quick pay attempt: works out again what the customer owes now (never what the browser says), keeps it,
 * and asks the gateway where to send them. A gateway that refuses leaves the attempt Failed. Pressing Pay again
 * within 10 minutes for the same amount reuses the open attempt. Tries are limited per visitor address, so someone
 * who knows a username cannot use up the customer's own tries, with a ceiling per customer. The customer row is
 * locked while the tries are counted, so parallel requests cannot pass the limit.
 */
final class StartOnlinePayment
{
    /**
     * Attempts one visitor address may start for one customer in an hour.
     */
    private const PER_ADDRESS_HOUR = 5;

    /**
     * Attempts for one customer in an hour, from every address together.
     */
    private const PER_ACCOUNT_HOUR = 30;

    /**
     * How long an open attempt is reused (a Paytm txnToken lasts 15 minutes).
     */
    private const REUSE_MINUTES = 10;

    public function __construct(private QuickPay $quickPay, private PaymentGateways $gateways) {}

    /**
     * @throws NothingToPay|TooManyPaymentAttempts|GatewayUnavailable
     */
    public function handle(int $accountId, string $gatewayKey, ?string $clientIp = null): StartedPayment
    {
        if (! in_array($gatewayKey, $this->gateways->enabled(), true)) {
            throw new GatewayUnavailable("The payment gateway {$gatewayKey} is not turned on.");
        }

        $gateway = $this->gateways->make($gatewayKey) ?? throw new GatewayUnavailable("Unknown payment gateway {$gatewayKey}.");

        $opened = DB::transaction(function () use ($accountId, $gatewayKey, $clientIp): OnlinePayment|StartedPayment {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            $quote = $this->quickPay->quote($account) ?? throw new NothingToPay;
            $attempt = [
                'account_id' => $account->id,
                'gateway' => $gatewayKey,
                'amount' => $quote->total,
                'dues_amount' => $quote->dues,
                'renewal_amount' => $quote->renewal->price ?? '0',
                'currency' => BillingSettings::current()->currencyCode,
                'renewal_kind' => $quote->renewal?->kind,
                'renewal_plan_id' => $quote->renewal?->planId,
                'renewal_expiry_key' => $quote->renewal === null ? null : RechargeAccount::expiryKey($account),
            ];

            $open = $this->openAttempt($attempt);

            if ($open !== null) {
                return $open;
            }

            $lastHour = OnlinePayment::query()->where('account_id', $account->id)->where('created_at', '>=', now()->subHour());

            if ((clone $lastHour)->count() >= self::PER_ACCOUNT_HOUR
                || (clone $lastHour)->where('client_ip', $clientIp)->count() >= self::PER_ADDRESS_HOUR) {
                throw new TooManyPaymentAttempts;
            }

            return OnlinePayment::query()->create([...$attempt,
                'public_id' => strtoupper((string) Str::ulid()),
                'status' => OnlinePaymentStatus::Pending,
                'client_ip' => $clientIp,
            ]);
        }, attempts: 3);

        if ($opened instanceof StartedPayment) {
            return $opened;
        }

        try {
            $start = $gateway->start($opened, route('pay.return', $gatewayKey));
        } catch (GatewayUnavailable $refused) {
            $opened->update(['status' => OnlinePaymentStatus::Failed, 'note' => mb_substr($refused->getMessage(), 0, 255)]);

            throw $refused;
        }

        $opened->update(['gateway_start' => $start->toArray()]);

        return new StartedPayment($opened, $start);
    }

    /**
     * A Pending attempt for exactly this payment, opened in the last minutes, with the gateway's start kept.
     *
     * @param  array<string, mixed>  $attempt
     */
    private function openAttempt(array $attempt): ?StartedPayment
    {
        $candidates = OnlinePayment::query()
            ->where('account_id', $attempt['account_id'])
            ->where('gateway', $attempt['gateway'])
            ->where('status', OnlinePaymentStatus::Pending)
            ->whereNotNull('gateway_start')
            ->where('created_at', '>=', now()->subMinutes(self::REUSE_MINUTES))
            ->latest('id')->get();

        foreach ($candidates as $payment) {
            $same = Decimal::compare((string) $payment->amount, (string) $attempt['amount']) === 0
                && Decimal::compare((string) $payment->dues_amount, (string) $attempt['dues_amount']) === 0
                && Decimal::compare((string) $payment->renewal_amount, (string) $attempt['renewal_amount']) === 0
                && $payment->currency === $attempt['currency']
                && (string) $payment->renewal_kind === (string) $attempt['renewal_kind']
                && (string) $payment->renewal_plan_id === (string) $attempt['renewal_plan_id']
                && (string) $payment->renewal_expiry_key === (string) $attempt['renewal_expiry_key'];
            $start = $same ? GatewayStart::fromArray($payment->gateway_start ?? []) : null;

            if ($start !== null) {
                return new StartedPayment($payment, $start);
            }
        }

        return null;
    }
}
