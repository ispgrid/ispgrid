<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\RadiusAttributeSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RadiusAttributeSeeder::class);
    AppSetting::put('phone_digits', '10');
    $this->actingAs(User::factory()->create());
});

/**
 * The add form with the fields every PPPoE customer needs.
 */
function billingCustomerForm(): Testable
{
    return Livewire::test('pages::customers.form')
        ->set('fullname', 'Example Customer')
        ->set('service_type', 'PPP')
        ->set('username', 'example1')
        ->set('password', 'secret12');
}

function savedCustomer(string $username = 'example1'): Account
{
    return Account::query()->where('username', $username)->firstOrFail();
}

it('saves Free, discount and terms', function () {
    billingCustomerForm()
        ->set('billing_free', true)
        ->set('discount_percent', '50')
        ->set('payment_terms_days', '7')
        ->call('save')
        ->assertHasNoErrors();

    expect(savedCustomer())->billing_free->toBeTrue()->discount_percent->toBe('50.00')->payment_terms_days->toBe(7);
});

it('starts new customers with the default payment terms', function () {
    AppSetting::put('billing.default_terms_days', '15');

    billingCustomerForm()->assertSet('payment_terms_days', '15')->call('save')->assertHasNoErrors();

    expect(savedCustomer()->payment_terms_days)->toBe(15);
});

it('pricing fields ignore a tampered request', function () {
    $account = Account::factory()->create(['discount_percent' => '10', 'billing_free' => false]);
    $this->actingAs(staffLacking(Permission::SetCustomerPricing));

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->assertDontSee(__('Discount (%)'))
        ->set('discount_percent', '100')
        ->set('billing_free', true)
        ->set('place_of_supply', '07')
        ->set('payment_terms_days', '30')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh())->discount_percent->toBe('10.00')->billing_free->toBeFalse()->place_of_supply->toBeNull()->payment_terms_days->toBe(30);
});

it('refuses a discount above 100%', function () {
    billingCustomerForm()->set('discount_percent', '120')->call('save')->assertHasErrors(['discount_percent']);
});

it('asks place of supply only with a GSTIN in India', function () {
    $form = billingCustomerForm()->assertSet('taxIdLabel', '');

    turnInvoicingOn();
    $form = billingCustomerForm();

    expect($form->instance()->showsPlaceOfSupply())->toBeFalse();

    $form->set('tax_id', '09ABCDE1234F1Z5')->assertSee(__('Place of supply'))->assertSet('place_of_supply', '09');

    expect($form->instance()->showsPlaceOfSupply())->toBeTrue()
        ->and($form->instance()->taxIdLabel())->toBe('GSTIN');
});

it('warns that invoices will charge IGST', function () {
    turnInvoicingOn();

    billingCustomerForm()
        ->set('tax_id', '07ABCDE1234F1Z5')
        ->assertDontSee('Invoices for this customer will charge IGST, not CGST and SGST.')
        ->set('place_of_supply', '07')
        ->assertSee('Invoices for this customer will charge IGST, not CGST and SGST.')
        ->call('save')
        ->assertHasNoErrors();

    expect(savedCustomer())->place_of_supply->toBe('07')->tax_id->toBe('07ABCDE1234F1Z5');
});

it('warns when the GSTIN is from another state than the place of supply', function () {
    turnInvoicingOn();

    billingCustomerForm()
        ->set('tax_id', '07ABCDE1234F1Z5')
        ->assertSee('This GSTIN is registered in Delhi (07), but the place of supply is Uttar Pradesh (09).');
});

it('refuses an invalid GSTIN in India', function () {
    turnInvoicingOn();

    billingCustomerForm()->set('tax_id', '09ABCDE1234')->call('save')->assertHasErrors(['tax_id' => __('Enter a valid GSTIN, e.g. 09ABCDE1234F1Z5.')]);
});

it('takes any tax ID outside India', function () {
    turnInvoicingOn(country: 'GB', taxSetup: 'single', tax: ['name' => 'VAT', 'rate' => '20']);
    AppSetting::put('tax_id_label', 'VAT number');

    billingCustomerForm()->set('tax_id', 'GB123456789')->call('save')->assertHasNoErrors();

    expect(savedCustomer()->place_of_supply)->toBeNull();
});

it('warns about non-English letters', function () {
    billingCustomerForm()
        ->set('fullname', 'राजेश कुमार')
        ->assertSee(__('Invoices print English letters only. Write this in English letters for the invoice: :fields.', ['fields' => __('Full name')]))
        ->call('save')
        ->assertHasNoErrors();
});

it('keeps a separate billing address', function () {
    billingCustomerForm()
        ->set('address.line1', '12 Main Road')
        ->set('separate_billing', true)
        ->set('billing_name', 'Example Traders')
        ->set('billing_address.line1', 'Head Office')
        ->set('billing_address.city', 'Lucknow')
        ->call('save')
        ->assertHasNoErrors();

    $account = savedCustomer();

    expect($account->billing_name)->toBe('Example Traders')
        ->and($account->billingAddress->line1)->toBe('Head Office')
        ->and($account->address->line1)->toBe('12 Main Road');

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->assertSet('separate_billing', true)
        ->assertSet('billing_address.city', 'Lucknow')
        ->set('separate_billing', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh())->billing_name->toBeNull()->billing_address_id->toBeNull();
});

it('needs the first line of a separate billing address', function () {
    billingCustomerForm()->set('separate_billing', true)->set('billing_address.city', 'Lucknow')->call('save')->assertHasErrors(['billing_address.line1']);
});

it('bills another name at the installation address', function () {
    billingCustomerForm()->set('separate_billing', true)->set('billing_name', 'Example Traders')->call('save')->assertHasNoErrors();

    expect(savedCustomer())->billing_name->toBe('Example Traders')->billing_address_id->toBeNull();
});

it('logs billing changes', function () {
    $account = Account::factory()->create();

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('password', 'secret12')
        ->set('billing_free', true)
        ->set('payment_terms_days', '7')
        ->call('save')
        ->assertHasNoErrors();

    $entry = Activity::query()->where('event', 'billing-changed')->sole();

    expect($entry->attribute_changes['old'])->toBe(['billing' => 'Paid', 'payment_terms' => '0'])
        ->and($entry->attribute_changes['attributes'])->toBe(['billing' => 'Free', 'payment_terms' => '7']);
});

it('logs nothing when billing did not change', function () {
    $account = Account::factory()->create();

    Livewire::test('pages::customers.form', ['account' => $account->id])->set('password', 'secret12')->call('save')->assertHasNoErrors();

    expect(Activity::query()->where('event', 'billing-changed')->exists())->toBeFalse();
});
