<?php

use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Recharge;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    AppSetting::put('expiry_mode', 'exact');
    $this->plan = Plan::factory()->create(['name' => 'Home 30', 'price' => 500, 'validity' => 30]);
    $this->account = Account::factory()->create(['plan_id' => $this->plan->id, 'expiry' => '2026-10-31 10:00:00']);
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::RechargeCustomers));
});

function rechargeDialog(Account $account)
{
    return Livewire::test('pages::customers.show', ['account' => $account->id])->call('openRecharge');
}

it('shows the breakdown instead of a free amount', function () {
    turnInvoicingOn();

    rechargeDialog($this->account)
        ->assertSee('CGST 9%')
        ->assertSee('SGST 9%')
        ->assertSee('590.00')
        ->assertDontSeeHtml('wire:model="recharge_amount"')
        ->assertSeeHtml('discount_value');
});

it('records the payment taken in the dialog', function () {
    turnInvoicingOn();
    $upi = PaymentMethod::query()->where('name', 'UPI')->sole();

    rechargeDialog($this->account)
        ->set('payment_method_id', (string) $upi->id)
        ->set('payment_amount', '300')
        ->set('payment_reference', 'UPI-123')
        ->call('recharge')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->sole();
    expect($invoice->status)->toBe(InvoiceStatus::PartlyPaid)
        ->and($invoice->balance)->toBe('290.00')
        ->and(Payment::query()->sole()->only(['payment_method_id', 'amount', 'reference']))->toBe(['payment_method_id' => $upi->id, 'amount' => '300.00', 'reference' => 'UPI-123'])
        ->and(Recharge::query()->sole()->amount)->toBe('590.00');
});

it('leaves the invoice unpaid when no payment is taken', function () {
    turnInvoicingOn();

    rechargeDialog($this->account)->set('paid_now', false)->call('recharge')->assertHasNoErrors();

    expect(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Payment::query()->count())->toBe(0);
});

it('refuses a payment above the total', function () {
    turnInvoicingOn();

    rechargeDialog($this->account)->set('payment_amount', '600')->call('recharge')->assertHasErrors('payment_amount');

    expect(Recharge::query()->count())->toBe(0);
});

it('opens with the customer saved discount filled in', function () {
    $this->account->update(['discount_percent' => 10]);
    rechargeDialog($this->account)->assertSet('discount_type', 'percent')->assertSet('discount_value', '10');

    $this->account->update(['discount_type' => 'amount', 'discount_amount' => 100]);
    rechargeDialog($this->account)->assertSet('discount_type', 'amount')->assertSet('discount_value', '100.00');
});

it('gives a discount at the recharge, shown in the breakdown and on the invoice', function () {
    turnInvoicingOn();

    rechargeDialog($this->account)
        ->set('discount_type', 'amount')
        ->set('discount_value', '50')
        ->assertSee('-50.00')
        ->set('discount_reason', 'Late connection')
        ->call('recharge')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->sole();
    $entry = Activity::query()->where('event', 'recharged')->sole();
    expect($invoice->lines->sole()->discount_amount)->toBe('50.00')
        ->and($invoice->total)->toBe('531.00')
        ->and(Recharge::query()->sole()->amount)->toBe('531.00')
        ->and($entry->attribute_changes['attributes']['discount'])->toBe('50.00')
        ->and($entry->attribute_changes['attributes']['discount_reason'])->toBe('Late connection')
        ->and($this->account->fresh()->discount_type)->toBe('percent');
});

it('takes a discount without a reason', function () {
    turnInvoicingOn();

    rechargeDialog($this->account)->set('discount_value', '10')->call('recharge')->assertHasNoErrors();

    expect(Invoice::query()->sole()->total)->toBe('531.00')
        ->and(Activity::query()->where('event', 'recharged')->sole()->attribute_changes['attributes'])->not->toHaveKey('discount_reason');
});

it('refuses a discount above 100% or above the plan price', function (string $type, string $value, bool $refused) {
    turnInvoicingOn();

    $dialog = rechargeDialog($this->account)->set('discount_type', $type)->set('discount_value', $value)->call('recharge');

    $refused ? $dialog->assertHasErrors('discount_value') : $dialog->assertHasNoErrors();
})->with([
    'percent 101' => ['percent', '101', true],
    'amount above the price' => ['amount', '500.01', true],
    'amount equal to the price' => ['amount', '500', false],
    'not a number' => ['amount', 'abc', true],
]);

it('keeps the discount for future renewals with Set customer pricing', function () {
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::RechargeCustomers, Permission::SetCustomerPricing));

    rechargeDialog($this->account)
        ->assertSeeHtml('wire:model="discount_keep"')
        ->set('discount_type', 'amount')
        ->set('discount_value', '100')
        ->set('discount_keep', true)
        ->call('recharge')
        ->assertHasNoErrors();

    $entry = Activity::query()->where('event', 'billing-changed')->sole();
    expect($this->account->fresh())->discount_type->toBe('amount')->discount_amount->toBe('100.00')->discount_percent->toBe('0.00')
        ->and($entry->attribute_changes['old'])->toBe(['discount' => '0'])
        ->and($entry->attribute_changes['attributes'])->toBe(['discount' => '100.00']);
});

it('leaves the saved discount alone when the switch is off', function () {
    $this->account->update(['discount_percent' => 10]);

    rechargeDialog($this->account)->set('discount_value', '20')->call('recharge')->assertHasNoErrors();

    expect($this->account->fresh())->discount_percent->toBe('10.00')->discount_type->toBe('percent')
        ->and(Activity::query()->where('event', 'billing-changed')->exists())->toBeFalse();
});

it('refuses to keep a discount without Set customer pricing', function () {
    rechargeDialog($this->account)
        ->assertDontSeeHtml('wire:model="discount_keep"')
        ->set('discount_value', '20')
        ->set('discount_keep', true)
        ->call('recharge')
        ->assertForbidden();

    expect(Recharge::query()->count())->toBe(0);
});

it('refills the amount with the price less the discount when invoicing is off', function () {
    rechargeDialog($this->account)
        ->assertSet('recharge_amount', '500.00')
        ->set('discount_type', 'amount')
        ->set('discount_value', '120')
        ->assertSet('recharge_amount', '380.00')
        ->set('discount_type', 'percent')
        ->assertSet('recharge_amount', '0.00')
        ->set('discount_value', '10')
        ->assertSet('recharge_amount', '450.00');
});

it('takes the discount in Change plan and Renew in advance', function () {
    turnInvoicingOn();
    $other = Plan::factory()->create(['name' => 'Home 60', 'price' => 1000, 'validity' => 30]);
    $card = Livewire::test('pages::customers.plan-card', ['accountId' => $this->account->id]);

    $card->call('openAdvance')
        ->set('paid_now', false)
        ->set('discount_type', 'amount')
        ->set('discount_value', '100')
        ->call('bookRenewal')
        ->assertHasNoErrors();

    expect(AdvanceRenewal::query()->sole()->invoice->lines->sole()->discount_amount)->toBe('100.00');

    $card->call('cancelRenewal')
        ->call('openChangePlan')
        ->set('change_plan_id', (string) $other->id)
        ->set('discount_type', 'percent')
        ->set('discount_value', '10')
        ->call('changePlan')
        ->assertHasNoErrors();

    expect(Invoice::query()->latest('id')->first()->lines->sole()->discount_amount)->toBe('100.00')
        ->and(Invoice::query()->latest('id')->first()->total)->toBe('1062.00');
});

it('says a Free customer gets no invoice', function () {
    turnInvoicingOn();
    $this->account->update(['billing_free' => true]);

    rechargeDialog($this->account)
        ->assertSee('Free customer: no invoice')
        ->assertDontSeeHtml('wire:model="recharge_amount"')
        ->assertDontSeeHtml('wire:model="payment_method_id"')
        ->call('recharge')
        ->assertHasNoErrors();

    expect(Recharge::query()->sole()->amount)->toBe('0.00')
        ->and(Invoice::query()->count())->toBe(0);
});

it('keeps the editable amount when invoicing is off', function () {
    $this->account->update(['discount_percent' => 10]);

    rechargeDialog($this->account)
        ->assertSeeHtml('wire:model="recharge_amount"')
        ->assertSet('recharge_amount', '450.00')
        ->assertDontSee('CGST')
        ->set('recharge_amount', '400')
        ->call('recharge')
        ->assertHasNoErrors();

    expect(Recharge::query()->sole()->amount)->toBe('400.00');
});

it('takes the payment in Change plan and Renew in advance', function () {
    turnInvoicingOn();
    $card = Livewire::test('pages::customers.plan-card', ['accountId' => $this->account->id]);

    $card->call('openAdvance')->assertSee('CGST 9%')->call('bookRenewal')->assertHasNoErrors();
    $other = Plan::factory()->create(['name' => 'Home 50', 'price' => 500, 'validity' => 30]);
    $card->call('openChangePlan')->set('change_plan_id', (string) $other->id)->set('paid_now', false)->call('changePlan')->assertHasNoErrors();

    $renewal = AdvanceRenewal::query()->sole();
    expect($renewal->invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($renewal->amount)->toBe('590.00')
        ->and(Invoice::query()->where('id', '!=', $renewal->invoice_id)->sole()->status)->toBe(InvoiceStatus::Unpaid);
});

it('gives every field on the customer page its own id', function () {
    turnInvoicingOn();
    $this->actingAs(staffHolding(...Permission::cases()));

    foreach (['details', 'billing'] as $tab) {
        $html = (string) $this->get(route('customers.show', ['account' => $this->account->id, 'tab' => $tab]))->assertOk()->getContent();

        preg_match_all('/\sid="([^"]+)"/', $html, $ids);
        expect(array_keys(array_filter(array_count_values($ids[1]), fn (int $count): bool => $count > 1)))->toBe([]);
    }
});
