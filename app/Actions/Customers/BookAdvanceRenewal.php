<?php

namespace App\Actions\Customers;

use App\Actions\Billing\IssueInvoice;
use App\Billing\BillingSettings;
use App\Billing\ChargeOptions;
use App\Billing\InvoicePolicy;
use App\Billing\RechargeInvoice;
use App\Enums\AdvanceRenewalStatus;
use App\Enums\InvoiceKind;
use App\Exceptions\RenewalAlreadyWaiting;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\Plan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Books a renewal that starts when the customer's current period ends. Nothing changes for the customer until
 * then; customers:renewals starts it as a recharge recorded for the staff member who booked it.
 * An invoiced customer gets the invoice now, for the future period, with the payment taken at booking; starting the
 * renewal later issues none. A Free customer books it at 0.
 */
final class BookAdvanceRenewal
{
    public function __construct(private IssueInvoice $issueInvoice) {}

    /**
     * @throws ModelNotFoundException when the customer or the plan was deleted meanwhile
     * @throws RenewalAlreadyWaiting when one is already waiting
     * @throws InvalidArgumentException when the customer has no plan or no running period, or the plan starts at
     *                                  the first login
     */
    public function handle(int $accountId, int $planId, CarbonInterface $expiry, string $amount, ?string $note, ChargeOptions $charge = new ChargeOptions): AdvanceRenewal
    {
        return DB::transaction(function () use ($accountId, $planId, $expiry, $amount, $note, $charge): AdvanceRenewal {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            // Shared lock: a delete of this plan waits for the commit and then counts this renewal.
            $plan = Plan::query()->sharedLock()->findOrFail($planId);

            if ($account->plan_id === null || $account->expiry === null || $account->expiry->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('An advance renewal needs a plan and an expiry in the future.');
            }

            if ($plan->hotspot_starts_at_first_login) {
                throw new InvalidArgumentException('A plan that starts at the first login cannot be renewed in advance.');
            }

            if ($account->waitingRenewal()->exists()) {
                throw new RenewalAlreadyWaiting;
            }

            $renewal = AdvanceRenewal::query()->create([
                'account_id' => $account->id,
                'plan_id' => $plan->id,
                'amount' => $account->billing_free ? '0' : $amount,
                'starts_at' => $account->expiry,
                'expiry' => $expiry,
                'note' => $note,
                'user_id' => auth()->id(),
                'status' => AdvanceRenewalStatus::Waiting,
            ]);

            if (InvoicePolicy::invoicesRecharge($account, $settings = BillingSettings::current())) {
                $invoice = $this->issueInvoice->handle(
                    $account, RechargeInvoice::lines($account, $plan, $settings, $charge->adjustedPrice, $account->expiry, $expiry, null), $plan->round_off,
                    InvoiceKind::Recharge, now(), $charge->payment, $account->expiry, $expiry,
                );
                $renewal->update(['invoice_id' => $invoice->id, 'amount' => $invoice->total]);
            }

            activity()
                ->performedOn($account)
                ->event('advance-booked')
                ->withChanges(['old' => [], 'attributes' => [
                    'plan' => $plan->name,
                    'starts' => $account->expiry->format(RechargeAccount::DATE_FORMAT),
                    'expiry' => $expiry->format(RechargeAccount::DATE_FORMAT),
                    'amount' => $renewal->invoice_id === null && ! $account->billing_free ? $amount : (string) $renewal->amount,
                    'note' => $note,
                    ...($charge->adjustReason === null ? [] : ['adjust_reason' => $charge->adjustReason]),
                ]])
                ->log('advance-booked');

            return $renewal;
        }, attempts: 3);
    }
}
