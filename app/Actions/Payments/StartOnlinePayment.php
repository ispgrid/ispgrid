<?php

namespace App\Actions\Payments;

use App\Actions\Customers\RechargeAccount;
use App\Billing\BillingSettings;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\GatewayUnavailable;
use App\Exceptions\NothingToPay;
use App\Exceptions\TooManyPaymentAttempts;
use App\Models\Account;
use App\Models\OnlinePayment;
use App\Payments\PaymentGateways;
use App\Payments\QuickPay;
use App\Payments\StartedPayment;
use Illuminate\Support\Str;

/**
 * Opens a quick pay attempt: works out again what the customer owes now (never what the browser says), keeps it,
 * and asks the gateway where to send them. A gateway that refuses leaves the attempt Failed.
 */
final class StartOnlinePayment
{
    /**
     * Attempts one customer may start in an hour.
     */
    private const PER_HOUR = 5;

    public function __construct(private QuickPay $quickPay, private PaymentGateways $gateways) {}

    /**
     * @throws NothingToPay|TooManyPaymentAttempts|GatewayUnavailable
     */
    public function handle(int $accountId, string $gatewayKey): StartedPayment
    {
        $account = Account::query()->findOrFail($accountId);
        $quote = $this->quickPay->quote($account) ?? throw new NothingToPay;

        if (! in_array($gatewayKey, $this->gateways->enabled(), true)) {
            throw new GatewayUnavailable("The payment gateway {$gatewayKey} is not turned on.");
        }

        if (OnlinePayment::query()->where('account_id', $account->id)->where('created_at', '>=', now()->subHour())->count() >= self::PER_HOUR) {
            throw new TooManyPaymentAttempts;
        }

        $payment = OnlinePayment::query()->create([
            'public_id' => strtoupper((string) Str::ulid()),
            'account_id' => $account->id,
            'gateway' => $gatewayKey,
            'amount' => $quote->total,
            'dues_amount' => $quote->dues,
            'renewal_amount' => $quote->renewal->price ?? '0',
            'currency' => BillingSettings::current()->currencyCode,
            'renewal_kind' => $quote->renewal?->kind,
            'renewal_plan_id' => $quote->renewal?->planId,
            'renewal_expiry_key' => $quote->renewal === null ? null : RechargeAccount::expiryKey($account),
            'status' => OnlinePaymentStatus::Pending,
        ]);

        $gateway = $this->gateways->make($gatewayKey) ?? throw new GatewayUnavailable("Unknown payment gateway {$gatewayKey}.");

        try {
            $start = $gateway->start($payment, route('pay.return', $gatewayKey));
        } catch (GatewayUnavailable $refused) {
            $payment->update(['status' => OnlinePaymentStatus::Failed, 'note' => mb_substr($refused->getMessage(), 0, 255)]);

            throw $refused;
        }

        return new StartedPayment($payment, $start);
    }
}
