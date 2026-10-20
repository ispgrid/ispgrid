<?php

use App\Billing\BillingSettings;
use App\Billing\Tax\TaxContext;
use App\Billing\Tax\TaxSetups;
use App\Models\AppSetting;

it('reads defaults when nothing is saved', function () {
    $settings = BillingSettings::current();

    expect($settings->enabled)->toBeFalse()
        ->and($settings->invoiceHotspot)->toBeTrue()
        ->and($settings->taxSetup)->toBe('none')
        ->and($settings->numbering->format)->toBe('INV-{N}')
        ->and($settings->numbering->yearStartMonth)->toBe(1)
        ->and($settings->defaultTermsDays)->toBe(0)
        ->and($settings->canIssue())->toBeFalse();
});

it('reads saved settings', function () {
    AppSetting::put('billing.enabled', '1');
    AppSetting::put('billing.company', json_encode(['name' => 'Example ISP', 'country' => 'IN', 'state' => 'Uttar Pradesh']));
    AppSetting::put('billing.tax_setup', 'india-gst');
    AppSetting::put('billing.tax', json_encode(['rate' => '12', 'state' => '09']));
    AppSetting::put('billing.numbering_format', 'LINK{FY}-{N}');
    AppSetting::put('billing.default_terms_days', '7');

    $settings = BillingSettings::current();

    expect($settings->canIssue())->toBeTrue()
        ->and($settings->company->name)->toBe('Example ISP')
        ->and($settings->tax()->id())->toBe('india-gst')
        ->and($settings->taxConfig)->toBe(['rate' => '12', 'state' => '09', 'item_code' => '998422'])
        ->and($settings->numbering->format)->toBe('LINK{FY}-{N}')
        ->and($settings->numbering->yearStartMonth)->toBe(4)
        ->and($settings->defaultTermsDays)->toBe(7);
});

it('falls back to no tax when the setup does not fit the country', function () {
    AppSetting::put('billing.company', json_encode(['name' => 'Example ISP', 'country' => 'GB']));
    AppSetting::put('billing.tax_setup', 'india-gst');

    expect(BillingSettings::current()->tax()->id())->toBe('none');
});

it('splits GST into CGST and SGST in the home state', function () {
    $components = app(TaxSetups::class)->get('india-gst')->components(['rate' => '18', 'state' => '09'], new TaxContext('09'));

    expect(array_map(fn ($component) => [$component->name, $component->rate], $components))->toBe([['CGST', '9'], ['SGST', '9']]);
});

it('charges IGST for another state', function () {
    $components = app(TaxSetups::class)->get('india-gst')->components(['rate' => '18', 'state' => '09'], new TaxContext('07'));

    expect(array_map(fn ($component) => [$component->name, $component->rate], $components))->toBe([['IGST', '18']]);
});

it('charges one named tax', function () {
    $components = app(TaxSetups::class)->get('single')->components(['name' => 'VAT', 'rate' => '20'], new TaxContext(null));

    expect(array_map(fn ($component) => [$component->name, $component->rate], $components))->toBe([['VAT', '20']]);
});

it('offers India GST only for India', function () {
    $setups = app(TaxSetups::class);

    expect(array_map(fn ($setup) => $setup->id(), $setups->forCountry('GB')))->toBe(['none', 'single'])
        ->and(array_map(fn ($setup) => $setup->id(), $setups->forCountry('IN')))->toBe(['none', 'single', 'india-gst'])
        ->and($setups->get('india-gst')->invoiceTitle())->toBe('TAX INVOICE')
        ->and($setups->get('none')->invoiceTitle())->toBe('INVOICE');
});
