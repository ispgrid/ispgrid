<?php

namespace App\Billing;

use App\Actions\Billing\IssueInvoice;
use App\Enums\ValidityUnit;
use App\Models\Account;
use App\Models\Plan;
use Carbon\CarbonInterface;

/**
 * The one line of a recharge invoice: the plan name, then the bill period (or the validity waiting for the first
 * login) and the plan speed, at the plan price (or the adjusted one) less the customer's discount. The text is
 * English, as the invoice prints it.
 */
final class RechargeInvoice
{
    public const DATE_FORMAT = 'd M Y';

    public static function draft(Account $account, Plan $plan, BillingSettings $settings, ?string $adjustedPrice, ?CarbonInterface $from, ?CarbonInterface $to, ?int $pendingMinutes): InvoiceDraft
    {
        return IssueInvoice::draft($account, self::lines($account, $plan, $settings, $adjustedPrice, $from, $to, $pendingMinutes), $plan->round_off, $settings);
    }

    /**
     * @return list<LineInput>
     */
    public static function lines(Account $account, Plan $plan, BillingSettings $settings, ?string $adjustedPrice, ?CarbonInterface $from, ?CarbonInterface $to, ?int $pendingMinutes): array
    {
        $when = $pendingMinutes !== null ? self::validityText($pendingMinutes)
            : ($from !== null && $to !== null ? 'Charges for period '.$from->format(self::DATE_FORMAT).' to '.$to->format(self::DATE_FORMAT) : null);
        $itemCode = $settings->tax()->itemCodeLabel() === null ? null
            : (trim((string) $plan->item_code) !== '' ? trim((string) $plan->item_code) : (($settings->taxConfig['item_code'] ?? '') ?: null));

        return [new LineInput(
            description: $plan->name,
            subDescription: implode(' · ', array_filter([$when, self::speedText($plan)])) ?: null,
            itemCode: $itemCode,
            quantity: '1',
            unitPrice: $adjustedPrice ?? (string) $plan->price,
            discountPercent: (string) $account->discount_percent,
            taxed: $plan->charge_tax,
        )];
    }

    /**
     * "Validity: 3 hours from the first login", for a first-login plan whose clock has not started.
     */
    public static function validityText(int $minutes): string
    {
        $days = intdiv($minutes, ValidityUnit::Days->minutes());
        $hours = intdiv($minutes, ValidityUnit::Hours->minutes());
        $text = $minutes % ValidityUnit::Days->minutes() === 0
            ? $days.($days === 1 ? ' day' : ' days')
            : $hours.($hours === 1 ? ' hour' : ' hours');

        return "Validity: {$text} from the first login";
    }

    /**
     * "30 Mbps down / 6 Mbps up" from the plan's MikroTik speeds (25M, 512k).
     */
    private static function speedText(Plan $plan): ?string
    {
        $speed = fn (?string $value): string => preg_replace(['/^(\d+)M$/', '/^(\d+)k$/'], ['$1 Mbps', '$1 kbps'], (string) $value) ?? (string) $value;

        if (trim((string) $plan->download) === '' || trim((string) $plan->upload) === '') {
            return null;
        }

        return $speed($plan->download).' down / '.$speed($plan->upload).' up';
    }
}
