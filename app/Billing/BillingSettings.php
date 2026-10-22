<?php

namespace App\Billing;

use App\Billing\Tax\TaxSetup;
use App\Billing\Tax\TaxSetups;
use App\Models\AppSetting;
use App\Support\Currency;

/**
 * The Billing settings (billing.* in app_settings) with their defaults. Invoicing is off until an operator turns
 * it on, and then needs a company name and country.
 */
final readonly class BillingSettings
{
    /**
     * @param  array<string, string>  $taxConfig  the tax setup's fields (single: name, rate; india-gst: rate, state, item_code)
     */
    public function __construct(
        public bool $enabled,
        public bool $invoiceHotspot,
        public Company $company,
        public Numbering $numbering,
        public string $taxSetup,
        public array $taxConfig,
        public bool $pricesIncludeTax,
        public int $defaultTermsDays,
        public string $logo,
        public int $decimals, // invoice amounts: the currency's decimals
        public string $currencyCode,
    ) {}

    public static function current(): self
    {
        $saved = AppSetting::query()->where('key', 'like', 'billing.%')->pluck('value', 'key')->all();
        $value = fn (string $key, string $default): string => isset($saved["billing.{$key}"]) ? (string) $saved["billing.{$key}"] : $default;
        $json = function (string $key) use ($value): array {
            $decoded = json_decode($value($key, '{}'), true);

            return is_array($decoded) ? $decoded : [];
        };

        $company = Company::fromArray($json('company'));
        $setups = app(TaxSetups::class);
        $offered = array_map(fn (TaxSetup $setup): string => $setup->id(), $setups->forCountry($company->country));
        $taxSetup = in_array($value('tax_setup', 'none'), $offered, true) ? $value('tax_setup', 'none') : 'none';
        $taxConfig = array_map(
            fn (mixed $field): string => is_scalar($field) ? (string) $field : '',
            array_merge($setups->get($taxSetup)->configDefaults(), array_intersect_key($json('tax'), $setups->get($taxSetup)->configDefaults())),
        );

        return new self(
            enabled: $value('enabled', '0') === '1',
            invoiceHotspot: $value('invoice_hotspot', '1') === '1',
            company: $company,
            numbering: new Numbering(
                format: $value('numbering_format', 'INV-{N}'),
                digits: (int) $value('number_digits', '0'),
                restartYearly: $value('restart_yearly', '0') === '1',
                yearStartMonth: (int) $value('year_start_month', $company->country === 'IN' ? '4' : '1'),
            ),
            taxSetup: $taxSetup,
            taxConfig: $taxConfig,
            pricesIncludeTax: $value('prices_include_tax', '0') === '1',
            defaultTermsDays: (int) $value('default_terms_days', '0'),
            logo: $value('logo', ''),
            decimals: Currency::decimals(),
            currencyCode: (string) AppSetting::valueOf('currency_code', 'INR'),
        );
    }

    /**
     * These settings with other numbering, for showing the next number before it is saved.
     */
    public function withNumbering(Numbering $numbering): self
    {
        return new self($this->enabled, $this->invoiceHotspot, $this->company, $numbering, $this->taxSetup, $this->taxConfig,
            $this->pricesIncludeTax, $this->defaultTermsDays, $this->logo, $this->decimals, $this->currencyCode);
    }

    /**
     * The tax setup in use (no tax when the saved one does not fit the company's country).
     */
    public function tax(): TaxSetup
    {
        return app(TaxSetups::class)->get($this->taxSetup);
    }

    /**
     * Whether invoices can be issued now: invoicing is on and the company has a name and country.
     */
    public function canIssue(): bool
    {
        return $this->enabled && $this->company->name !== '' && $this->company->country !== '';
    }
}
