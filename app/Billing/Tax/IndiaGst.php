<?php

namespace App\Billing\Tax;

use App\Billing\Decimal;
use App\Billing\India\GstStates;
use Illuminate\Validation\Rule;

/**
 * India GST: CGST and SGST (half the rate each) when the place of supply is the company's state, IGST at the full
 * rate for another state. The rate is a setting, so a change in the law is a settings change.
 */
final class IndiaGst implements TaxSetup
{
    public function id(): string
    {
        return 'india-gst';
    }

    public function label(): string
    {
        return __('India GST');
    }

    public function countries(): array
    {
        return ['IN'];
    }

    public function components(array $config, TaxContext $context): array
    {
        $rate = $this->totalRate($config);
        $home = $config['state'] ?? null;

        if ($context->placeOfSupply === null || $context->placeOfSupply === $home) {
            $half = Decimal::trim(Decimal::div($rate, '2', 3));

            return [new TaxComponent('CGST', $half), new TaxComponent('SGST', $half)];
        }

        return [new TaxComponent('IGST', Decimal::trim($rate))];
    }

    public function totalRate(array $config): string
    {
        return $config['rate'] ?? '18';
    }

    public function configRules(): array
    {
        return [
            'tax.rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'tax.state' => ['required', Rule::in(GstStates::codes())],
            'tax.item_code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z]*$/'],
        ];
    }

    public function configDefaults(): array
    {
        return ['rate' => '18', 'state' => '', 'item_code' => '998422'];
    }

    public function itemCodeLabel(): string
    {
        return 'SAC/HSN';
    }

    public function totalName(array $config): string
    {
        return 'GST';
    }

    public function invoiceTitle(): string
    {
        return 'TAX INVOICE';
    }

    public function usesPlaceOfSupply(): bool
    {
        return true;
    }
}
