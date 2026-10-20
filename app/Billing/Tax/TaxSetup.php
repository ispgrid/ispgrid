<?php

namespace App\Billing\Tax;

/**
 * How one country (or any country) taxes an invoice. A new country is a new class registered in TaxSetups; the
 * calculator, the settings page and the PDF only use this interface.
 */
interface TaxSetup
{
    public function id(): string;

    public function label(): string;

    /**
     * The ISO country codes this setup is offered for, or null for every country.
     *
     * @return list<string>|null
     */
    public function countries(): ?array;

    /**
     * The taxes charged for this sale.
     *
     * @param  array<string, string>  $config
     * @return list<TaxComponent>
     */
    public function components(array $config, TaxContext $context): array;

    /**
     * The combined rate of a sale at home.
     *
     * @param  array<string, string>  $config
     */
    public function totalRate(array $config): string;

    /**
     * Validation rules for its fields on the Billing settings page, keyed under "tax.".
     *
     * @return array<string, mixed>
     */
    public function configRules(): array;

    /**
     * The defaults of its fields.
     *
     * @return array<string, string>
     */
    public function configDefaults(): array;

    /**
     * The heading of the item code column (SAC/HSN in India), or null for none.
     */
    public function itemCodeLabel(): ?string;

    public function invoiceTitle(): string;

    public function usesPlaceOfSupply(): bool;
}
