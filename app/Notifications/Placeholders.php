<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Recharge;
use App\Payments\PayCode;
use App\Payments\QuickPay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The values a rule can put into a template, in the order the rule lists them. Each is cut to 30 characters,
 * the DLT limit for one variable.
 */
final class Placeholders
{
    public const MAX_LENGTH = 30;

    /** Values every customer has. */
    public const COMMON = ['customer_name', 'username', 'mobile', 'plan_name', 'plan_speed', 'plan_price',
        'expiry_date', 'expiry_datetime', 'days_left', 'isp_name', 'support_number', 'pay_code'];

    /** Values only a recharge has. */
    public const RECHARGE = ['amount_paid', 'recharge_reference', 'recharge_note', 'recharge_date', 'recharge_datetime'];

    /** Values only a payment has. */
    public const PAYMENT = ['payment_amount', 'receipt_number', 'invoice_number', 'payment_method', 'balance_due'];

    /** @var array<string, string> */
    private array $settings = [];

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'customer_name' => __('Customer name'),
            'username' => __('Username'),
            'mobile' => __('Mobile'),
            'plan_name' => __('Plan name'),
            'plan_speed' => __('Plan speed'),
            'plan_price' => __('Plan price'),
            'amount_paid' => __('Amount paid'),
            'recharge_reference' => __('Recharge reference'),
            'recharge_note' => __('Recharge note'),
            'recharge_date' => __('Recharge date'),
            'recharge_datetime' => __('Recharge date and time'),
            'expiry_date' => __('Expiry date'),
            'expiry_datetime' => __('Expiry date and time'),
            'days_left' => __('Days left'),
            'isp_name' => __('ISP name'),
            'support_number' => __('Support number'),
            'payment_amount' => __('Payment amount'),
            'receipt_number' => __('Receipt number'),
            'invoice_number' => __('Invoice number'),
            'payment_method' => __('Payment method'),
            'balance_due' => __('Balance due'),
            'pay_code' => __('Pay code'),
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array{values: list<string>, trimmed: bool}
     */
    public function values(array $keys, ?Account $account, ?Recharge $recharge, CarbonImmutable $now, ?PaymentNotice $payment = null): array
    {
        $values = [];
        $trimmed = false;

        foreach ($keys as $key) {
            $value = $account === null ? '['.(self::labels()[$key] ?? $key).']' : $this->resolve($key, $account, $recharge, $now, $payment);

            if (mb_strlen($value) > self::MAX_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_LENGTH);
                $trimmed = true;
            }

            $values[] = $value;
        }

        return ['values' => $values, 'trimmed' => $trimmed];
    }

    /**
     * The approved text with {1}, {2}, ... replaced by the values.
     *
     * @param  list<string>  $values
     */
    public static function preview(?string $approvedText, array $values): ?string
    {
        if ($approvedText === null || $approvedText === '') {
            return null;
        }

        $pairs = [];

        foreach ($values as $index => $value) {
            $pairs['{'.($index + 1).'}'] = $value;
        }

        return strtr($approvedText, $pairs);
    }

    private function resolve(string $key, Account $account, ?Recharge $recharge, CarbonImmutable $now, ?PaymentNotice $payment): string
    {
        $plan = $account->plan;
        $expiry = $account->expiry;
        $rechargedAt = $recharge?->created_at;

        return match ($key) {
            'customer_name' => (string) ($account->fullname ?: $account->username),
            'username' => (string) $account->username,
            'mobile' => (string) $account->mobile,
            'plan_name' => (string) $plan?->name,
            'plan_speed' => (string) $plan?->download,
            'plan_price' => $plan === null ? '' : $this->amount((string) $plan->price),
            'amount_paid' => $recharge === null ? '' : $this->amount((string) $recharge->amount),
            'recharge_reference' => $recharge === null ? '' : (string) $recharge->id,
            'recharge_note' => (string) $recharge?->note,
            'recharge_date' => $rechargedAt === null ? '' : $rechargedAt->format($this->dateFormat()),
            'recharge_datetime' => $rechargedAt === null ? '' : $rechargedAt->format($this->dateTimeFormat()),
            'expiry_date' => $expiry === null ? '' : $expiry->format($this->dateFormat()),
            'expiry_datetime' => $expiry === null ? '' : $expiry->format($this->dateTimeFormat()),
            'days_left' => $expiry === null ? '' : (string) $this->daysLeft($now, $expiry),
            'isp_name' => $this->setting('notice_isp_name', ''),
            'support_number' => $this->setting('notice_phone', ''),
            'payment_amount' => $payment === null ? '' : $this->amount($payment->amount),
            'receipt_number' => (string) $payment?->receipts,
            'invoice_number' => (string) $payment?->invoices,
            'payment_method' => (string) $payment?->method,
            'balance_due' => $payment === null ? '' : $this->amount($payment->balanceDue),
            'pay_code' => QuickPay::isOpen() ? PayCode::for($account->id) : '',
            default => '',
        };
    }

    /**
     * Calendar days from today to the expiry day, never below zero.
     */
    private function daysLeft(CarbonImmutable $now, CarbonInterface $expiry): int
    {
        return max(0, (int) $now->startOfDay()->diffInDays(CarbonImmutable::instance($expiry)->startOfDay(), false));
    }

    private function dateFormat(): string
    {
        return $this->setting('notify_date_format', 'd-M-Y');
    }

    private function dateTimeFormat(): string
    {
        return $this->dateFormat().' '.$this->setting('notify_time_format', 'h:iA');
    }

    private function amount(string $amount): string
    {
        return number_format((float) $amount, (int) $this->setting('currency_decimals', '2'), '.', '');
    }

    private function setting(string $key, string $default): string
    {
        return $this->settings[$key] ??= (string) AppSetting::valueOf($key, $default);
    }
}
