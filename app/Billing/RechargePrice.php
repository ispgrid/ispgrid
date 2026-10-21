<?php

namespace App\Billing;

use App\Models\Account;
use App\Models\Plan;

/**
 * The amount a recharge records: 0 for a Free customer, the invoice total when the recharge is invoiced, otherwise
 * the plan price (or the adjusted price) less the customer's discount, without tax.
 */
final class RechargePrice
{
    public static function amount(Account $account, Plan $plan, BillingSettings $settings, ?string $adjustedPrice = null): string
    {
        if ($account->billing_free) {
            return Decimal::round('0', 2);
        }

        if (InvoicePolicy::invoicesRecharge($account, $settings)) {
            return RechargeInvoice::draft($account, $plan, $settings, $adjustedPrice, null, null, null)->total;
        }

        $price = $adjustedPrice ?? (string) $plan->price;

        return Decimal::round(Decimal::sub($price, Decimal::percentOf($price, (string) $account->discount_percent)), $settings->decimals);
    }
}
