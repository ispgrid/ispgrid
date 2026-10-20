<?php

namespace App\Billing\Tax;

use App\Billing\Decimal;

/**
 * One named tax at one rate (VAT, sales tax, ...), for any country.
 */
final class SingleTax implements TaxSetup
{
    public function id(): string
    {
        return 'single';
    }

    public function label(): string
    {
        return __('One tax (VAT, sales tax, ...)');
    }

    public function countries(): ?array
    {
        return null;
    }

    public function components(array $config, TaxContext $context): array
    {
        return [new TaxComponent($config['name'] ?? 'Tax', Decimal::trim($this->totalRate($config)))];
    }

    public function totalRate(array $config): string
    {
        return $config['rate'] ?? '0';
    }

    public function configRules(): array
    {
        return [
            'tax.name' => ['required', 'string', 'max:40'],
            'tax.rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,3'],
        ];
    }

    public function configDefaults(): array
    {
        return ['name' => 'VAT', 'rate' => '0'];
    }

    public function itemCodeLabel(): ?string
    {
        return null;
    }

    public function invoiceTitle(): string
    {
        return 'INVOICE';
    }

    public function usesPlaceOfSupply(): bool
    {
        return false;
    }
}
