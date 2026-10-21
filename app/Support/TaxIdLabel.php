<?php

namespace App\Support;

use App\Billing\BillingSettings;
use App\Models\AppSetting;

/**
 * The name of the customer's tax ID. Under India GST it is always the GSTIN (it is checked as one and prints as
 * one on invoices); otherwise it is the Tax ID label in App settings.
 */
final class TaxIdLabel
{
    /**
     * The form field's label; empty hides the field.
     */
    public static function forField(): string
    {
        return BillingSettings::current()->tax()->usesPlaceOfSupply() ? 'GSTIN' : (string) AppSetting::valueOf('tax_id_label', '');
    }

    /**
     * The label where a tax ID is shown, never empty.
     */
    public static function forDisplay(): string
    {
        return self::forField() ?: __('Tax ID');
    }
}
