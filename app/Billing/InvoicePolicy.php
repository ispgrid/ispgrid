<?php

namespace App\Billing;

use App\Enums\ServiceType;
use App\Models\Account;

/**
 * Which recharges get an invoice: invoicing is on and ready, the customer is not Free, and a Hotspot customer only
 * while Invoice Hotspot recharges is on.
 */
final class InvoicePolicy
{
    public static function invoicesRecharge(Account $account, BillingSettings $settings): bool
    {
        return $settings->canIssue()
            && ! $account->billing_free
            && ($account->service_type !== ServiceType::Hotspot || $settings->invoiceHotspot);
    }
}
