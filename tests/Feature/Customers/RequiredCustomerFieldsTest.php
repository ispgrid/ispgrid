<?php

use App\Actions\Import\ImportCustomers;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\RequiredCustomerFields;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusAttributeSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RadiusAttributeSeeder::class);
    AppSetting::put('phone_digits', '10');
    AppSetting::put('tax_id_label', 'VAT number');
    AppSetting::put('business_id_label', 'PAN');
    $this->actingAs(User::factory()->create());
});

function requiredFieldsForm(): Testable
{
    return Livewire::test('pages::customers.form')
        ->set('fullname', 'Example Customer')
        ->set('service_type', 'PPP')
        ->set('username', 'example1')
        ->set('password', 'secret12');
}

/**
 * One valid PPPoE import row with every other column blank.
 *
 * @return list<array{line: int, values: array<string, string>}>
 */
function requiredFieldsImportRow(): array
{
    $blank = array_fill_keys(app(ImportCustomers::class)->columns(), '');

    return [['line' => 2, 'values' => [...$blank, 'username' => 'alice', 'password' => 'secret12', 'service_type' => 'PPP', 'fullname' => 'Alice Rao']]];
}

it('makes a ticked field required in the form and the import, with the same message', function (string $field, string $column, string $message) {
    RequiredCustomerFields::save([$field]);

    requiredFieldsForm()->call('save')->assertHasErrors([$column => $message]);

    $import = errorLines(app(ImportCustomers::class)->check(requiredFieldsImportRow()));
    $importColumn = str_starts_with($column, 'address.') ? substr($column, 8) : $column;

    expect($import)->toBe(["2 {$importColumn}: {$message}"])
        ->and(Account::query()->count())->toBe(0);
})->with([
    'mobile' => ['mobile', 'mobile', 'The mobile field is required.'],
    'whatsapp' => ['whatsapp', 'whatsapp', 'The whatsapp field is required.'],
    'other number' => ['alt_number', 'alt_number', 'The alt number field is required.'],
    'email' => ['email', 'email', 'The email field is required.'],
    'company' => ['company', 'company', 'The company field is required.'],
    'tax id' => ['tax_id', 'tax_id', 'The tax id field is required.'],
    'business id' => ['business_id', 'business_id', 'The business id field is required.'],
    'address' => ['address', 'address.line1', 'Enter the first address line.'],
]);

it('keeps every field optional until one is ticked', function () {
    requiredFieldsForm()->call('save')->assertHasNoErrors();

    expect(app(ImportCustomers::class)->check(requiredFieldsImportRow()))->toBe([]);
});

it('marks a required field with the star', function () {
    $star = '<span class="text-red-600 dark:text-red-400" aria-hidden="true"> *</span>';
    $html = fn (): string => (string) preg_replace('/<!--\[if (END)?BLOCK\]><!\[endif\]-->/', '', requiredFieldsForm()->html());

    expect($html())->not->toContain('Email'.$star);

    RequiredCustomerFields::save(['email', 'address']);

    expect($html())->toContain('Email'.$star)->toContain('Address line 1'.$star);
});

it('leaves a tax ID or business ID optional while its field is hidden', function () {
    RequiredCustomerFields::save(['tax_id', 'business_id']);
    AppSetting::put('tax_id_label', '');
    AppSetting::put('business_id_label', '');

    requiredFieldsForm()->call('save')->assertHasNoErrors();
});

it('saves the ticked fields in App settings and refuses others', function () {
    $this->seed(AppSettingSeeder::class);

    Livewire::test('pages::system.app-settings')
        ->assertSet('required_customer_fields', [])
        ->set('required_customer_fields', ['mobile', 'address'])
        ->call('save')
        ->assertHasNoErrors();

    expect(RequiredCustomerFields::current())->toBe(['mobile', 'address']);

    Livewire::test('pages::system.app-settings')
        ->assertSet('required_customer_fields', ['mobile', 'address'])
        ->set('required_customer_fields', ['fullname'])
        ->call('save')
        ->assertHasErrors(['required_customer_fields.0']);
});
