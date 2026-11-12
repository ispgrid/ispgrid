<?php

namespace App\Billing;

use App\Models\Account;
use App\Models\Plan;
use App\Support\Currency;

/**
 * The amount a recharge records: 0 for a Free customer, the invoice total when the recharge is invoiced, otherwise
 * the plan price less the discount (given at the recharge, or the customer's saved one), without tax.
 */
final class RechargePrice
{
    /**
     * @param  ?Discount  $discount  null: the customer's saved discount
     */
    public static function amount(Account $account, Plan $plan, BillingSettings $settings, ?Discount $discount = null): string
    {
        if ($account->billing_free) {
            return Decimal::round('0', Currency::decimals());
        }

        if (InvoicePolicy::invoicesRecharge($account, $settings)) {
            return RechargeInvoice::draft($account, $plan, $settings, $discount, null, null, null)->total;
        }

        $price = Decimal::round((string) $plan->price, $settings->decimals);
        $discount ??= Discount::forAccount($account);

        return Decimal::round(Decimal::sub($price, $discount->takenOff($price, $settings->decimals)), $settings->decimals);
    }
}
