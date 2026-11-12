<?php

namespace App\Billing;

use App\Actions\Billing\IssueInvoice;
use App\Enums\ValidityUnit;
use App\Models\Account;
use App\Models\Plan;
use Carbon\CarbonInterface;

/**
 * The one line of a recharge invoice: the plan name, then the bill period (or the validity waiting for the first
 * login), at the plan price less the discount (given at the recharge, or the customer's saved one). No speed: the plan name usually says
 * it, and the real speed can differ. The text is English, as the invoice prints it.
 */
final class RechargeInvoice
{
    public const DATE_FORMAT = 'd M Y';

    /**
     * @param  ?Discount  $discount  null: the customer's saved discount
     */
    public static function draft(Account $account, Plan $plan, BillingSettings $settings, ?Discount $discount, ?CarbonInterface $from, ?CarbonInterface $to, ?int $pendingMinutes): InvoiceDraft
    {
        return IssueInvoice::draft($account, self::lines($account, $plan, $settings, $discount, $from, $to, $pendingMinutes), $plan->round_off, $settings);
    }

    /**
     * @param  ?Discount  $discount  null: the customer's saved discount
     * @return list<LineInput>
     */
    public static function lines(Account $account, Plan $plan, BillingSettings $settings, ?Discount $discount, ?CarbonInterface $from, ?CarbonInterface $to, ?int $pendingMinutes): array
    {
        $when = $pendingMinutes !== null ? self::validityText($pendingMinutes)
            : ($from !== null && $to !== null ? 'Charges for period '.$from->format(self::DATE_FORMAT).' to '.$to->format(self::DATE_FORMAT) : null);
        $itemCode = $settings->tax()->itemCodeLabel() === null ? null
            : (trim((string) $plan->item_code) !== '' ? trim((string) $plan->item_code) : (($settings->taxConfig['item_code'] ?? '') ?: null));
        $discount ??= Discount::forAccount($account);

        return [new LineInput(
            description: $plan->name,
            subDescription: $when,
            itemCode: $itemCode,
            quantity: '1',
            unitPrice: (string) $plan->price,
            discountPercent: $discount->isAmount() || $discount->isNone() ? '0' : $discount->value,
            taxed: $plan->charge_tax,
            discountAmount: $discount->isAmount() && ! $discount->isNone() ? $discount->value : null,
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
}
