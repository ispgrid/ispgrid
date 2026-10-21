<?php

namespace App\Billing\Tax;

/**
 * Invoices without tax.
 */
final class NoTax implements TaxSetup
{
    public function id(): string
    {
        return 'none';
    }

    public function label(): string
    {
        return __('No tax');
    }

    public function countries(): ?array
    {
        return null;
    }

    public function components(array $config, TaxContext $context): array
    {
        return [];
    }

    public function totalRate(array $config): string
    {
        return '0';
    }

    public function configRules(): array
    {
        return [];
    }

    public function configDefaults(): array
    {
        return [];
    }

    public function itemCodeLabel(): ?string
    {
        return null;
    }

    public function totalName(array $config): string
    {
        return '';
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
