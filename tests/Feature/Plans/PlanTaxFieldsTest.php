<?php

use App\Models\AppSetting;
use App\Models\Plan;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function taxedPlanForm(): Testable
{
    return Livewire::test('pages::plans.form')
        ->set('name', 'Home 25')
        ->set('slug', 'home_25')
        ->set('validity', '30')
        ->set('price', '99')
        ->set('upload_speed', '25M')
        ->set('download_speed', '25M');
}

it('shows the price after tax', function () {
    turnInvoicingOn();

    taxedPlanForm()
        ->assertSee('Total GST (18%)')
        ->assertSee('17.82')
        ->assertSee('116.82')
        ->set('round_off', true)
        ->assertSee('117.00')
        ->set('do_not_charge_tax', true)
        ->assertDontSee('Total GST (18%)');
});

it('shows one named tax', function () {
    turnInvoicingOn(country: 'GB', taxSetup: 'single', tax: ['name' => 'VAT', 'rate' => '20']);

    taxedPlanForm()->set('price', '100')->assertSee('Total VAT (20%)')->assertSee('120.00')->assertDontSee('SAC/HSN');
});

it('hides tax fields when there is no tax', function () {
    taxedPlanForm()
        ->assertDontSee(__('Do not charge tax'))
        ->assertDontSee('SAC/HSN')
        ->assertSee(__('Round off the total'));
});

it('saves do not charge tax, round off and SAC', function () {
    turnInvoicingOn();

    taxedPlanForm()
        ->set('do_not_charge_tax', true)
        ->set('round_off', true)
        ->set('item_code', '998422')
        ->call('save')
        ->assertHasNoErrors();

    $plan = Plan::query()->where('slug', 'home_25')->firstOrFail();

    expect($plan)->charge_tax->toBeFalse()->round_off->toBeTrue()->item_code->toBe('998422');

    Livewire::test('pages::plans.form', ['plan' => $plan->id])
        ->assertSet('do_not_charge_tax', true)
        ->assertSet('round_off', true)
        ->assertSet('item_code', '998422');
});

it('refuses an item code with spaces', function () {
    turnInvoicingOn();

    taxedPlanForm()->set('item_code', '9984 22')->call('save')->assertHasErrors(['item_code']);
});

it('charges tax on a new plan by default', function () {
    AppSetting::put('billing.tax_setup', 'none');

    taxedPlanForm()->call('save')->assertHasNoErrors();

    expect(Plan::query()->where('slug', 'home_25')->firstOrFail())->charge_tax->toBeTrue()->round_off->toBeFalse();
});
