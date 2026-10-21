<?php

use App\Billing\BillingSettings;
use App\Enums\Permission;
use App\Models\AppSetting;
use App\Models\InvoiceCounter;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * The page with an Indian company filled in.
 */
function billingPageForIndia(): Testable
{
    return Livewire::test('pages::system.billing-settings')
        ->set('company_name', 'Example ISP')
        ->set('country', 'IN')
        ->set('tax_setup', 'india-gst')
        ->set('tax.state', '09');
}

it('opens only with Manage billing', function () {
    $this->actingAs(staffLacking(Permission::ManageBilling));

    $this->get(route('billing-settings.edit'))->assertForbidden();

    $this->actingAs(staffHolding(Permission::ManageBilling));

    $this->get(route('billing-settings.edit'))->assertOk()->assertSee(__('Company details'));
});

it('is in the System menu for Manage billing only', function () {
    $this->get(route('dashboard'))->assertSee(route('billing-settings.edit'));

    $this->actingAs(staffLacking(Permission::ManageBilling));

    $this->get(route('dashboard'))->assertDontSee(route('billing-settings.edit'));
});

it('saves company details and numbering', function () {
    Livewire::test('pages::system.billing-settings')
        ->set('company_name', 'Example ISP')
        ->set('address_line1', '12 Station Road')
        ->set('city', 'Prayagraj')
        ->set('country', 'GB')
        ->set('extra_lines.0.label', 'CIN')
        ->set('extra_lines.0.value', 'U00000XX0000PTC000000')
        ->set('bank_details', "Example Bank\nAccount 000123")
        ->set('numbering_format', 'EX{FY}-{N}')
        ->set('number_digits', '3')
        ->set('restart_yearly', true)
        ->set('year_start_month', '4')
        ->set('default_terms_days', '7')
        ->call('save')
        ->assertHasNoErrors();

    $settings = BillingSettings::current();

    expect($settings->company->name)->toBe('Example ISP')
        ->and($settings->company->addressLines)->toBe(['12 Station Road'])
        ->and($settings->company->extraLines)->toBe([['label' => 'CIN', 'value' => 'U00000XX0000PTC000000']])
        ->and($settings->company->bankDetails)->toBe("Example Bank\nAccount 000123")
        ->and($settings->numbering->format)->toBe('EX{FY}-{N}')
        ->and($settings->numbering->digits)->toBe(3)
        ->and($settings->numbering->restartYearly)->toBeTrue()
        ->and($settings->numbering->yearStartMonth)->toBe(4)
        ->and($settings->defaultTermsDays)->toBe(7);
});

it('refuses to turn invoicing on without a company name', function () {
    Livewire::test('pages::system.billing-settings')
        ->set('enabled', true)
        ->set('country', 'IN')
        ->call('save')
        ->assertHasErrors(['enabled']);

    expect(AppSetting::valueOf('billing.enabled'))->toBeNull();
});

it('turns invoicing on once the company is filled in', function () {
    billingPageForIndia()->set('enabled', true)->call('save')->assertHasNoErrors();

    expect(BillingSettings::current()->canIssue())->toBeTrue();
});

it('shows India GST only for India', function () {
    $page = Livewire::test('pages::system.billing-settings')->set('country', 'GB');

    expect(array_keys($page->instance()->taxSetups()))->toBe(['none', 'single']);

    $page->set('country', 'IN');

    expect(array_keys($page->instance()->taxSetups()))->toBe(['none', 'single', 'india-gst']);
});

it('resets the tax setup when the country no longer fits it', function () {
    billingPageForIndia()->call('save')->set('country', 'GB')->assertSet('tax_setup', 'none')->call('save')->assertHasNoErrors();

    expect(BillingSettings::current()->taxSetup)->toBe('none');
});

it('refuses India GST for another country', function () {
    Livewire::test('pages::system.billing-settings')
        ->set('company_name', 'Example ISP')
        ->set('country', 'GB')
        ->set('tax_setup', 'india-gst')
        ->call('save')
        ->assertHasErrors(['tax_setup']);
});

it('saves the GST rate', function () {
    billingPageForIndia()->set('tax.rate', '12')->set('prices_include_tax', true)->call('save')->assertHasNoErrors();

    $settings = BillingSettings::current();

    expect($settings->taxConfig['rate'])->toBe('12')
        ->and($settings->taxConfig['state'])->toBe('09')
        ->and($settings->pricesIncludeTax)->toBeTrue();
});

it('needs the company state for India GST', function () {
    billingPageForIndia()->set('tax.state', '')->call('save')->assertHasErrors(['tax.state']);
});

it('stores the logo as a file', function () {
    Storage::fake('billing');

    $page = Livewire::test('pages::system.billing-settings')
        ->set('logo', UploadedFile::fake()->image('logo.png', 160, 80))
        ->call('save')
        ->assertHasNoErrors();

    $first = (string) AppSetting::valueOf('billing.logo');

    expect(Storage::disk('billing')->files('logos'))->toBe([$first])
        ->and(AppSetting::query()->where('key', 'like', 'billing.%')->get()->every(fn (AppSetting $setting): bool => strlen((string) $setting->value) < 1000))->toBeTrue();

    $page->set('logo', UploadedFile::fake()->image('logo.jpg', 160, 80))->call('save')->assertHasNoErrors();

    expect(Storage::disk('billing')->files('logos'))->toHaveCount(2)
        ->and(Storage::disk('billing')->exists($first))->toBeTrue()
        ->and(AppSetting::valueOf('billing.logo'))->not->toBe($first);
});

it('removes the logo from new invoices but keeps the file', function () {
    Storage::fake('billing');
    $page = Livewire::test('pages::system.billing-settings')->set('logo', UploadedFile::fake()->image('logo.png'))->call('save');
    $path = (string) AppSetting::valueOf('billing.logo');

    $page->call('removeLogo');

    expect(AppSetting::valueOf('billing.logo'))->toBe('')
        ->and(Storage::disk('billing')->exists($path))->toBeTrue();
});

it('refuses a logo that is not an image', function () {
    Storage::fake('billing');

    Livewire::test('pages::system.billing-settings')
        ->set('logo', UploadedFile::fake()->create('logo.png', 10, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['logo']);

    expect(Storage::disk('billing')->allFiles())->toBe([]);
});

it('refuses a logo above 200 KB', function () {
    Storage::fake('billing');

    Livewire::test('pages::system.billing-settings')
        ->set('logo', UploadedFile::fake()->image('logo.png')->size(300))
        ->call('save')
        ->assertHasErrors(['logo']);
});

it('adds, renames, orders and turns off payment methods', function () {
    $page = Livewire::test('pages::system.billing-settings')
        ->set('new_method', 'Card')
        ->call('addMethod')
        ->assertHasNoErrors();

    $card = PaymentMethod::query()->where('name', 'Card')->firstOrFail();
    $cash = PaymentMethod::query()->where('name', 'Cash')->firstOrFail();

    $page->call('moveMethod', $card->id, 'up')
        ->call('toggleMethod', $cash->id)
        ->set("method_names.{$card->id}", 'Debit card')
        ->call('save')
        ->assertHasNoErrors();

    expect(PaymentMethod::query()->orderBy('position')->pluck('name')->all())->toBe(['Cash', 'UPI', 'Bank transfer', 'Debit card', 'Cheque'])
        ->and($cash->fresh()->active)->toBeFalse();
});

it('refuses a payment method name used twice', function () {
    Livewire::test('pages::system.billing-settings')
        ->set('new_method', 'cash')
        ->call('addMethod')
        ->assertHasErrors(['new_method']);
});

it('keeps a payment method that has payments', function () {
    $upi = PaymentMethod::query()->where('name', 'UPI')->firstOrFail();
    Payment::factory()->create(['payment_method_id' => $upi->id]);
    $cheque = PaymentMethod::query()->where('name', 'Cheque')->firstOrFail();

    Livewire::test('pages::system.billing-settings')
        ->call('deleteMethod', $upi->id)
        ->assertHasErrors(['method_names.'.$upi->id])
        ->call('toggleMethod', $upi->id)
        ->call('deleteMethod', $cheque->id)
        ->assertHasNoErrors();

    expect($upi->fresh()->active)->toBeFalse()
        ->and(PaymentMethod::query()->whereKey($cheque->id)->exists())->toBeFalse();
});

it('shows the next invoice number', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

    billingPageForIndia()
        ->set('numbering_format', 'LINK{FY}-{N}')
        ->set('restart_yearly', true)
        ->set('year_start_month', '4')
        ->assertSee('LINK26-27-1');
});

it('carries on numbering from another system', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

    Livewire::test('pages::system.billing-settings')->set('next_number', '190')->call('save')->assertHasNoErrors()->assertSet('next_number', '');

    expect(InvoiceCounter::query()->where('series', 'all')->value('next_number'))->toBe(190);
});

it('refuses a numbering format without the number', function () {
    Livewire::test('pages::system.billing-settings')->set('numbering_format', 'INV-{FY}')->call('save')->assertHasErrors(['numbering_format']);
});

it('needs the financial year in the format when numbers restart every year', function () {
    Livewire::test('pages::system.billing-settings')
        ->set('numbering_format', 'INV-{N}')
        ->set('restart_yearly', true)
        ->call('save')
        ->assertHasErrors(['numbering_format']);
});

it('sets the next number in the series saved with it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

    Livewire::test('pages::system.billing-settings')
        ->set('numbering_format', 'INV/{FY}/{N}')
        ->set('restart_yearly', true)
        ->set('year_start_month', '4')
        ->set('next_number', '500')
        ->call('save')
        ->assertHasNoErrors();

    expect(InvoiceCounter::query()->pluck('next_number', 'series')->all())->toBe(['2026-27' => 500]);
});

it('swaps the names of two payment methods', function () {
    $cash = PaymentMethod::query()->where('name', 'Cash')->value('id');
    $upi = PaymentMethod::query()->where('name', 'UPI')->value('id');

    Livewire::test('pages::system.billing-settings')
        ->set("method_names.{$cash}", 'UPI')
        ->set("method_names.{$upi}", 'Cash')
        ->call('save')
        ->assertHasNoErrors();

    expect(PaymentMethod::query()->whereKey($cash)->value('name'))->toBe('UPI');
});

it('refuses a GST rate whose half needs more than three decimals', function () {
    billingPageForIndia()->set('tax.rate', '18.125')->call('save')->assertHasErrors(['tax.rate']);
});
