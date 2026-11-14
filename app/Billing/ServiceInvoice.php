<?php

namespace App\Billing;

use App\Models\CustomerService;
use App\Models\Item;
use Carbon\CarbonInterface;

/**
 * The one line of a service invoice: the item name (a static IP followed by its address), then the period paid for,
 * at the amount charged, taxed as the item says. The text is English, as the invoice prints it.
 */
final class ServiceInvoice
{
    /**
     * @return list<LineInput>
     */
    public static function lines(CustomerService $service, Item $item, string $amount, CarbonInterface $from, CarbonInterface $to): array
    {
        $settings = BillingSettings::current();
        $address = $service->ip?->address;
        $itemCode = $settings->tax()->itemCodeLabel() === null ? null
            : (trim((string) $item->item_code) !== '' ? trim((string) $item->item_code) : (($settings->taxConfig['item_code'] ?? '') ?: null));

        return [new LineInput(
            description: $address !== null && $service->isStaticIp() ? "{$item->name} {$address}" : $item->name,
            subDescription: 'Charges for period '.$from->format(RechargeInvoice::DATE_FORMAT).' to '.$to->format(RechargeInvoice::DATE_FORMAT),
            itemCode: $itemCode,
            quantity: '1',
            unitPrice: $amount,
            discountPercent: '0',
            taxed: $item->taxable,
            itemId: $item->id,
        )];
    }
}
