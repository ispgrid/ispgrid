<?php

namespace App\Notifications;

/**
 * Common rules a new operator can start from: the trigger, when, the values in a sensible order and a suggested
 * message text with {1}, {2} where they go. The rule form fills itself from one; the operator still picks the
 * provider template their SMS or WhatsApp provider approved (with the same variables), which no preset can know.
 */
final class RulePresets
{
    /**
     * @return array<string, array{label: string, name: string, trigger: string, settings: array<string, string>, values: list<string>, text: string}>
     */
    public static function all(): array
    {
        return [
            'expiry-reminder' => [
                'label' => __('Expiry reminder, 3 days before'),
                'name' => __('Expiry reminder'),
                'trigger' => 'before_expiry',
                'settings' => ['days' => '3'],
                'values' => ['customer_name', 'plan_name', 'expiry_date', 'support_number'],
                'text' => 'Dear {1}, your {2} plan expires on {3}. Recharge to stay online. Help: {4}',
            ],
            'expired' => [
                'label' => __('Plan expired, on the day'),
                'name' => __('Plan expired'),
                'trigger' => 'after_expiry',
                'settings' => ['days' => '0'],
                'values' => ['customer_name', 'plan_name', 'expiry_date', 'support_number'],
                'text' => 'Dear {1}, your {2} plan expired on {3}. Recharge to get back online. Help: {4}',
            ],
            'recharge-done' => [
                'label' => __('Recharge done'),
                'name' => __('Recharge done'),
                'trigger' => 'recharge_done',
                'settings' => [],
                'values' => ['customer_name', 'plan_name', 'amount_paid', 'expiry_date', 'isp_name'],
                'text' => 'Dear {1}, your {2} plan is recharged. Paid {3}, valid till {4}. Thank you, {5}',
            ],
            'payment-received' => [
                'label' => __('Online payment received'),
                'name' => __('Online payment received'),
                'trigger' => 'online_payment_received',
                'settings' => [],
                'values' => ['customer_name', 'payment_amount', 'receipt_number', 'isp_name'],
                'text' => 'Dear {1}, we received your payment of {2}, receipt {3}. Thank you, {4}',
            ],
            'welcome' => [
                'label' => __('Welcome, when a customer is added'),
                'name' => __('Welcome'),
                'trigger' => 'customer_created',
                'settings' => [],
                'values' => ['isp_name', 'customer_name', 'username', 'support_number'],
                'text' => 'Welcome to {1}, {2}. Your username is {3}. Help: {4}',
            ],
        ];
    }
}
