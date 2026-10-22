<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * The customer fields the operator made required in App settings, on top of the fixed ones (full name, type,
 * username, password, IP). The customer form and the import both read them through CustomerRules. A tax ID or
 * business ID whose field is hidden (no label) is never required.
 */
final class RequiredCustomerFields
{
    public const SETTING = 'required_customer_fields';

    public const FIELDS = ['mobile', 'whatsapp', 'alt_number', 'email', 'address', 'company', 'tax_id', 'business_id'];

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'mobile' => __('Mobile'),
            'whatsapp' => __('WhatsApp'),
            'alt_number' => __('Other number'),
            'email' => __('Email'),
            'address' => __('Address'),
            'company' => __('Company'),
            'tax_id' => TaxIdLabel::forDisplay(),
            'business_id' => (string) AppSetting::valueOf('business_id_label', '') ?: __('Business ID'),
        ];
    }

    /**
     * The ticked fields, in the order of FIELDS.
     *
     * @return list<string>
     */
    public static function saved(): array
    {
        $ticked = explode(',', (string) AppSetting::valueOf(self::SETTING, ''));

        return array_values(array_intersect(self::FIELDS, $ticked));
    }

    /**
     * The ticked fields that apply now: a hidden tax ID or business ID field is left out.
     *
     * @return list<string>
     */
    public static function current(): array
    {
        $hidden = array_keys(array_filter([
            'tax_id' => TaxIdLabel::forField() === '',
            'business_id' => (string) AppSetting::valueOf('business_id_label', '') === '',
        ]));

        return array_values(array_diff(self::saved(), $hidden));
    }

    /**
     * @param  list<string>  $fields
     */
    public static function save(array $fields): void
    {
        AppSetting::put(self::SETTING, implode(',', array_values(array_intersect(self::FIELDS, $fields))));
    }
}
