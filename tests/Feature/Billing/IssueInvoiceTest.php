<?php

use App\Actions\Billing\IssueInvoice;
use App\Billing\BillingSettings;
use App\Billing\PaymentInput;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoicingNotReady;
use App\Models\Account;
use App\Models\Address;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    turnInvoicingOn();
});

function customerInPrayagraj(array $attributes = []): Account
{
    $address = Address::factory()->create(['line1' => '12 Main Road', 'area' => 'Civil Lines', 'city' => 'Prayagraj', 'postal_code' => '211001', 'state' => 'Uttar Pradesh', 'country' => 'India']);

    return Account::factory()->create(array_merge(['username' => 'example1', 'fullname' => 'Example Customer', 'address_id' => $address->id], $attributes));
}

function issue(Account $account, string $price = '99.00', ?PaymentInput $payment = null, string $date = '2026-10-20'): Invoice
{
    return app(IssueInvoice::class)->handle($account, billingLines($price), false, InvoiceKind::Recharge, CarbonImmutable::parse($date), $payment);
}

it('issues an invoice with the next number and the customer snapshot', function () {
    $invoice = issue(customerInPrayagraj());

    expect($invoice->number)->toBe('INV-1')
        ->and($invoice->title)->toBe('TAX INVOICE')
        ->and($invoice->status)->toBe(InvoiceStatus::Unpaid)
        ->and($invoice->total)->toBe('116.82')
        ->and($invoice->balance)->toBe('116.82')
        ->and($invoice->total_in_words)->toBe('Indian Rupee One Hundred Sixteen and Paise Eighty Two Only')
        ->and($invoice->place_of_supply)->toBe('09')
        ->and($invoice->item_code_label)->toBe('SAC/HSN')
        ->and($invoice->company['name'])->toBe('Example ISP')
        ->and($invoice->customer['billing_lines'])->toBe(['Example Customer', '12 Main Road', 'Civil Lines', 'Prayagraj 211001', 'Uttar Pradesh'])
        ->and($invoice->customer['connectivity_lines'])->toBe(['12 Main Road', 'Civil Lines', 'Prayagraj 211001', 'Uttar Pradesh'])
        ->and($invoice->lines)->toHaveCount(1)
        ->and($invoice->taxLines->map(fn ($line) => [$line->name, $line->amount])->all())->toBe([['CGST', '8.91'], ['SGST', '8.91']])
        ->and(Activity::query()->where('event', 'invoice-issued')->value('attribute_changes')->toArray()['attributes'])->toMatchArray(['invoice' => 'INV-1', 'total' => '116.82']);
});

it('an issued invoice keeps its snapshot', function () {
    $account = customerInPrayagraj();
    $invoice = issue($account);

    turnInvoicingOn(tax: ['rate' => '12', 'state' => '09']);
    AppSetting::put('billing.company', (string) json_encode(['name' => 'Renamed ISP', 'country' => 'IN']));
    $account->address->update(['line1' => '99 New Road']);
    $account->update(['fullname' => 'Someone Else']);

    $invoice->refresh();

    expect($invoice->company['name'])->toBe('Example ISP')
        ->and($invoice->taxLines->map(fn ($line) => $line->rate)->all())->toBe(['9.000', '9.000'])
        ->and($invoice->customer['billing_lines'][0])->toBe('Example Customer')
        ->and($invoice->customer['billing_lines'][1])->toBe('12 Main Road');
});

it('is due after the customer payment terms', function () {
    $invoice = issue(customerInPrayagraj(['payment_terms_days' => 7]));

    expect($invoice->due_date->toDateString())->toBe('2026-10-27')
        ->and($invoice->terms_days)->toBe(7);
});

it('bills a separate billing name and address', function () {
    $billing = Address::factory()->create(['line1' => 'Head Office', 'area' => null, 'city' => 'Lucknow', 'postal_code' => '226001', 'state' => 'Uttar Pradesh', 'country' => 'India']);
    $invoice = issue(customerInPrayagraj(['billing_name' => 'Example Traders', 'billing_address_id' => $billing->id]));

    expect($invoice->customer['billing_lines'])->toBe(['Example Traders', 'Head Office', 'Lucknow 226001', 'Uttar Pradesh'])
        ->and($invoice->customer['connectivity_lines'][0])->toBe('12 Main Road');
});

it('uses the place of supply only for customers with a GSTIN', function () {
    $withGstin = issue(customerInPrayagraj(['tax_id' => '07ABCDE1234F1Z5', 'place_of_supply' => '07']));
    $withoutGstin = issue(customerInPrayagraj(['username' => 'example2', 'place_of_supply' => '07']));

    expect($withGstin->place_of_supply)->toBe('07')
        ->and($withGstin->customer['place_label'])->toBe('Delhi (07)')
        ->and($withGstin->customer['tax_number'])->toBe('07ABCDE1234F1Z5')
        ->and($withGstin->taxLines->pluck('name')->all())->toBe(['IGST'])
        ->and($withoutGstin->place_of_supply)->toBe('09')
        ->and($withoutGstin->taxLines->pluck('name')->all())->toBe(['CGST', 'SGST']);
});

it('has no place of supply outside India', function () {
    turnInvoicingOn(country: 'GB', taxSetup: 'single', tax: ['name' => 'VAT', 'rate' => '20']);
    $invoice = issue(customerInPrayagraj(), '100.00');

    expect($invoice->place_of_supply)->toBeNull()
        ->and($invoice->title)->toBe('INVOICE')
        ->and($invoice->total)->toBe('120.00')
        ->and($invoice->item_code_label)->toBeNull();
});

it('records the payment taken when it was issued', function () {
    $cash = PaymentMethod::query()->where('name', 'Cash')->firstOrFail();
    $invoice = issue(customerInPrayagraj(), payment: new PaymentInput($cash->id, '116.82', null, now(), null));

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->balance)->toBe('0.00')
        ->and($invoice->payments)->toHaveCount(1);
});

it('marks an invoice with nothing to pay as paid', function () {
    $invoice = app(IssueInvoice::class)->handle(customerInPrayagraj(), billingLines('500.00', discount: '100'), false, InvoiceKind::Recharge, now());

    expect($invoice->total)->toBe('0.00')
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

it('refuses to issue before Company details are filled', function () {
    AppSetting::put('billing.company', (string) json_encode(['name' => '', 'country' => 'IN']));

    issue(customerInPrayagraj());
})->throws(InvoicingNotReady::class);

it('refuses to issue while invoicing is off', function () {
    AppSetting::put('billing.enabled', '0');

    issue(customerInPrayagraj());
})->throws(InvoicingNotReady::class);

it('rounds off the total when asked', function () {
    $invoice = app(IssueInvoice::class)->handle(customerInPrayagraj(), billingLines('99.00'), true, InvoiceKind::Recharge, now());

    expect([$invoice->total, $invoice->round_off])->toBe(['117.00', '0.18']);
});

it('works out the same breakdown the dialogs show', function () {
    $account = customerInPrayagraj(['tax_id' => '27ABCDE1234F1Z5', 'place_of_supply' => '27']);
    $draft = IssueInvoice::draft($account, billingLines('800.00', '10'), false, BillingSettings::current());

    expect($draft->taxLines[0]->label())->toBe('IGST 18%')
        ->and(issue($account, '8000.00')->taxLines->pluck('name')->all())->toBe(['IGST']);
});
