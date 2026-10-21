<?php

use App\Enums\Permission;
use App\Models\AppSetting;
use App\Models\GatewaySetting;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    turnInvoicingOn();
    $this->actingAs(staffHolding(Permission::ManageBilling));
});

it('is for staff with Billing settings only', function () {
    $this->get(route('online-payments.edit'))->assertOk()->assertSee('Paytm');

    $this->actingAs(staffHolding(Permission::ViewInvoices));
    $this->get(route('online-payments.edit'))->assertForbidden();
});

it('saves Paytm keys encrypted, never shows the key again and keeps it when left empty', function () {
    $page = Livewire::test('pages::system.online-payments')
        ->set('enabled.paytm', true)
        ->set('settings.paytm.environment', 'test')
        ->set('settings.paytm.mid', 'TESTMID')
        ->set('settings.paytm.merchant_key', 'secret#Key')
        ->call('save', 'paytm')
        ->assertHasNoErrors()
        ->assertSet('settings.paytm.merchant_key', '')
        ->assertDontSee('secret#Key')
        ->assertSee(__('Saved. Leave empty to keep it.'));

    $page->set('settings.paytm.mid', 'TESTMID2')->call('save', 'paytm')->assertHasNoErrors();

    expect(GatewaySetting::settingsFor('paytm'))->toMatchArray(['mid' => 'TESTMID2', 'merchant_key' => 'secret#Key'])
        ->and(DB::table('payment_gateways')->value('settings'))->not->toContain('secret#Key')
        ->and(json_encode(Activity::query()->pluck('attribute_changes')))->not->toContain('secret#Key');
});

it('checks the Paytm fields and the currency', function () {
    Livewire::test('pages::system.online-payments')
        ->set('settings.paytm.environment', 'prod')
        ->set('settings.paytm.host', 'not a url')
        ->call('save', 'paytm')
        ->assertHasErrors(['settings.paytm.environment', 'settings.paytm.mid', 'settings.paytm.merchant_key', 'settings.paytm.host']);

    AppSetting::put('currency_code', 'USD');
    Livewire::test('pages::system.online-payments')
        ->assertSee(__('This gateway does not take the currency set in App settings.'))
        ->set('enabled.paytm', true)
        ->set('settings.paytm.environment', 'test')
        ->set('settings.paytm.mid', 'M')
        ->set('settings.paytm.merchant_key', 'K')
        ->call('save', 'paytm')
        ->assertHasErrors('enabled.paytm');
});

it('opens the pay page only with invoicing and a gateway on', function () {
    Livewire::test('pages::system.online-payments')
        ->set('quick_pay', true)
        ->call('saveQuickPay')
        ->assertHasErrors('quick_pay');

    GatewaySetting::query()->create(['gateway' => 'paytm', 'enabled' => true, 'settings' => ['environment' => 'test', 'mid' => 'M', 'merchant_key' => 'K']]);

    Livewire::test('pages::system.online-payments')
        ->set('quick_pay', true)
        ->set('renewal_days', '61')
        ->call('saveQuickPay')
        ->assertHasErrors('renewal_days')
        ->set('renewal_days', '10')
        ->call('saveQuickPay')
        ->assertHasNoErrors()
        ->assertSee(route('pay.show'))
        ->assertSee(route('pay.webhook', 'paytm'));

    expect(AppSetting::valueOf('billing.quick_pay'))->toBe('1')
        ->and(AppSetting::valueOf('billing.quick_pay_renewal_days'))->toBe('10')
        ->and(Activity::query()->where('event', 'quick-pay-settings')->exists())->toBeTrue();

    $this->get(route('pay.show'))->assertOk();

    AppSetting::put('billing.enabled', '0');
    Livewire::test('pages::system.online-payments')->call('saveQuickPay')->assertHasErrors('quick_pay');
});

it('warns when the panel is not on https', function () {
    config(['app.url' => 'http://panel.example']);
    Livewire::test('pages::system.online-payments')->assertSee(__('The panel address does not start with https://. Payment gateways send customers back only to an https address: install with a domain first.'));

    config(['app.url' => 'https://panel.example']);
    Livewire::test('pages::system.online-payments')->assertDontSee('does not start with https://');
});
