<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RecordPayment;
use App\Billing\PaymentInput;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\GatewaySetting;
use App\Models\PaymentMethod;
use App\Payments\PaymentGateways;
use Livewire\Livewire;
use Tests\Fakes\FakeGateway;

beforeEach(function () {
    turnInvoicingOn();
    useFakeGateway();
});

it('offers a gateway only while it is on and charges in the billing currency', function () {
    $gateways = app(PaymentGateways::class);

    expect($gateways->enabled())->toBe(['fake'])
        ->and($gateways->make('fake'))->toBeInstanceOf(FakeGateway::class)
        ->and($gateways->make('nope'))->toBeNull();

    AppSetting::put('currency_code', 'USD');
    expect($gateways->enabled())->toBe([]);

    AppSetting::put('currency_code', 'INR');
    GatewaySetting::query()->where('gateway', 'fake')->update(['enabled' => false]);
    expect($gateways->enabled())->toBe([])
        ->and($gateways->make('fake'))->toBeInstanceOf(FakeGateway::class);
});

it('keeps gateway settings encrypted and lists the secret values', function () {
    $raw = DB::table('payment_gateways')->where('gateway', 'fake')->value('settings');

    expect($raw)->not->toContain('test-secret')
        ->and(app(PaymentGateways::class)->secretValues('fake', GatewaySetting::settingsFor('fake')))->toBe(['test-secret']);
});

it('makes one fixed Online payment method per gateway', function () {
    $method = app(PaymentGateways::class)->method('fake');

    expect($method->name)->toBe('Online (Test gateway)')
        ->and($method->gateway)->toBe('fake')
        ->and(app(PaymentGateways::class)->method('fake')->id)->toBe($method->id);
});

it('never offers the gateway method to staff', function () {
    $this->actingAs(staffHolding(...Permission::cases()));
    $online = app(PaymentGateways::class)->method('fake');
    $account = Account::factory()->create();
    $invoice = app(IssueInvoice::class)->handle($account, billingLines('500'), false, InvoiceKind::Manual, now());
    $payment = app(RecordPayment::class)->handle($invoice->id, new PaymentInput($online->id, '100', 'TXN1', now(), null));
    $cash = PaymentMethod::query()->where('name', 'Cash')->sole();
    $staffPayment = app(RecordPayment::class)->handle($invoice->id, new PaymentInput($cash->id, '100', null, now(), null));

    $tab = Livewire::test('pages::customers.billing-tab', ['accountId' => $account->id])->call('openRecordPayment', $invoice->id);

    expect($tab->instance()->paymentMethods)->not->toContain('Online (Test gateway)')->toContain('Cash');

    Livewire::test('pages::payments.show', ['payment' => $staffPayment->id])
        ->call('openEditPayment')
        ->assertDontSee('Online (Test gateway)')
        ->set('edit_method_id', (string) $online->id)
        ->call('editPayment')
        ->assertHasErrors('edit_method_id');

    Livewire::test('pages::payments.show', ['payment' => $payment->id])
        ->assertSee('Online (Test gateway)')
        ->assertDontSee(__('Edit'))
        ->call('openEditPayment')
        ->assertForbidden();

    Livewire::test('pages::system.billing-settings')
        ->assertDontSee('Online (Test gateway)')
        ->call('toggleMethod', $online->id)
        ->assertNotFound();

    expect($online->fresh()->active)->toBeTrue();
});
