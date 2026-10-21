<?php

namespace App\Actions\Payments;

use App\Actions\Billing\RecordPayment;
use App\Actions\Customers\BookAdvanceRenewal;
use App\Actions\Customers\RechargeAccount;
use App\Billing\ChargeOptions;
use App\Billing\Decimal;
use App\Billing\PaymentInput;
use App\Enums\InvoiceStatus;
use App\Enums\OnlinePaymentStatus;
use App\Enums\RechargeKind;
use App\Exceptions\ExpiryChangedMeanwhile;
use App\Exceptions\GatewayUnavailable;
use App\Exceptions\RenewalAlreadyWaiting;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\PaymentMethod;
use App\Payments\GatewayResult;
use App\Payments\GatewayStatus;
use App\Payments\PaymentGateways;
use App\Payments\QuickPay;
use App\Payments\RenewalOffer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns a quick pay attempt the gateway confirms into money on the books: the dues part onto the customer's open
 * invoices, oldest first, then the renewal part as a recharge (plan expired) or an advance renewal (not yet), each
 * with its invoice and payment. Asks the gateway itself first: a return or webhook only says "check now". Safe to run
 * any number of times from anywhere: a settled attempt is never touched again. Whatever cannot be placed (an amount
 * the gateway changed, dues paid meanwhile, a renewal no longer possible) is left for staff as Needs attention.
 */
final class SettleOnlinePayment
{
    public function __construct(
        private PaymentGateways $gateways,
        private QuickPay $quickPay,
        private RecordPayment $recordPayment,
        private RechargeAccount $recharge,
        private BookAdvanceRenewal $bookRenewal,
    ) {}

    public function handle(string $publicId): OnlinePayment
    {
        $payment = OnlinePayment::query()->where('public_id', $publicId)->firstOrFail();

        if ($payment->status->settled()) {
            return $payment;
        }

        $gateway = $this->gateways->make($payment->gateway) ?? throw new GatewayUnavailable("Unknown payment gateway {$payment->gateway}.");
        $result = $gateway->status($payment);

        return DB::transaction(function () use ($payment, $result): OnlinePayment {
            $locked = OnlinePayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status->settled()) {
                return $locked;
            }

            $locked->fill([
                'gateway_status' => $result->providerStatus === null ? null : mb_substr($result->providerStatus, 0, 40),
                'gateway_txn_id' => $result->txnId === null ? $locked->gateway_txn_id : mb_substr($result->txnId, 0, 100),
                'gateway_response' => $result->raw === [] ? $locked->gateway_response : $result->raw,
            ]);

            match ($result->status) {
                GatewayStatus::Pending => $locked->save(),
                GatewayStatus::Failed => $locked->fill(['status' => OnlinePaymentStatus::Failed])->save(),
                GatewayStatus::Paid => $this->paid($locked, $result),
            };

            return $locked;
        }, attempts: 3);
    }

    private function paid(OnlinePayment $payment, GatewayResult $result): void
    {
        $paidAt = $result->paidAt ?? now();
        $payment->fill(['paid_at' => $paidAt, 'settled_at' => now()]);
        $currency = $payment->currency;

        if ($result->amount === null || Decimal::compare($result->amount, (string) $payment->amount) !== 0) {
            $payment->fill([
                'status' => OnlinePaymentStatus::NeedsAttention,
                'note' => __('The gateway reports :currency :paid, not :currency :asked. Nothing was recorded.', [
                    'currency' => $currency, 'paid' => $result->amount ?? '?', 'asked' => (string) $payment->amount,
                ]),
            ])->save();
            $this->log($payment);

            return;
        }

        $method = $this->gateways->method($payment->gateway);
        $reference = $payment->gateway_txn_id ?? $payment->public_id;
        $unplaced = [];

        $leftover = $this->payDues($payment, $method, $reference, $paidAt);

        if (Decimal::compare($leftover, '0') > 0) {
            $unplaced[] = __(':currency :amount of the dues (they were paid meanwhile)', ['currency' => $currency, 'amount' => $leftover]);
        }

        if (Decimal::compare((string) $payment->renewal_amount, '0') > 0 && ! $this->renew($payment, $method, $reference, $paidAt)) {
            $unplaced[] = __(':currency :amount for the renewal (it can no longer be booked)', ['currency' => $currency, 'amount' => (string) $payment->renewal_amount]);
        }

        $payment->fill($unplaced === []
            ? ['status' => OnlinePaymentStatus::Paid]
            : ['status' => OnlinePaymentStatus::NeedsAttention, 'note' => mb_substr(__('Not used: :parts. Refund it or settle it by hand.', ['parts' => implode('; ', $unplaced)]), 0, 255)],
        )->save();
        $this->log($payment);
    }

    /**
     * Pays the dues part into the open invoices, oldest first. Returns what is left over.
     */
    private function payDues(OnlinePayment $payment, PaymentMethod $method, string $reference, CarbonInterface $paidAt): string
    {
        $left = (string) $payment->dues_amount;
        $invoices = Invoice::query()->where('account_id', $payment->account_id)
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::PartlyPaid])
            ->orderBy('issue_date')->orderBy('id')->lockForUpdate()->get();

        foreach ($invoices as $invoice) {
            if (Decimal::compare($left, '0') <= 0) {
                break;
            }

            $amount = Decimal::compare($left, (string) $invoice->balance) < 0 ? $left : (string) $invoice->balance;

            if (Decimal::compare($amount, '0') <= 0) {
                continue;
            }

            $this->recordPayment->handle($invoice->id, new PaymentInput($method->id, $amount, $reference, $paidAt, null));
            $left = Decimal::round(Decimal::sub($left, $amount), 2);
        }

        return Decimal::round($left, 2);
    }

    /**
     * Books the renewal the customer paid for, if their plan, price and expiry are still what they were offered.
     */
    private function renew(OnlinePayment $payment, PaymentMethod $method, string $reference, CarbonInterface $paidAt): bool
    {
        $account = Account::query()->findOrFail($payment->account_id);
        $offer = $this->quickPay->renewal($account, anyTime: true);

        if ($offer === null || $offer->planId !== $payment->renewal_plan_id || Decimal::compare($offer->price, (string) $payment->renewal_amount) !== 0
            || RechargeAccount::expiryKey($account) !== $payment->renewal_expiry_key) {
            return false;
        }

        $charge = new ChargeOptions(payment: new PaymentInput($method->id, $offer->price, $reference, $paidAt, null));

        try {
            if ($offer->kind === RenewalOffer::RECHARGE) {
                $this->recharge->handle($account->id, $offer->planId, $offer->expiry, $offer->price, null, $payment->renewal_expiry_key, RechargeKind::Recharge, null, $charge);
            } else {
                $this->bookRenewal->handle($account->id, $offer->planId, $offer->expiry, $offer->price, null, $charge);
            }
        } catch (ExpiryChangedMeanwhile|RenewalAlreadyWaiting|InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function log(OnlinePayment $payment): void
    {
        $gateway = $this->gateways->class($payment->gateway);

        activity()
            ->performedOn(Account::withTrashed()->findOrFail($payment->account_id))
            ->event('online-payment')
            ->withProperties(['online_payment' => $payment->public_id])
            ->withChanges(['old' => [], 'attributes' => array_filter([
                'amount' => (string) $payment->amount,
                'gateway' => $gateway === null ? $payment->gateway : $gateway::label(),
                'reference' => $payment->gateway_txn_id,
                'status' => $payment->status->value,
                'note' => $payment->note,
            ], fn (?string $value): bool => $value !== null && $value !== '')])
            ->log('online-payment');
    }
}
