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
        ->assertDontSeeHtml('wire:model.live.blur="adjust_price"');
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

it('refuses Adjust price without Set customer pricing', function () {
    turnInvoicingOn();

    rechargeDialog($this->account)
        ->set('adjust_price', '100')
        ->set('adjust_reason', 'Friend')
        ->call('recharge')
        ->assertForbidden();

    expect(Recharge::query()->count())->toBe(0);
});

it('needs a reason to adjust the price', function () {
    turnInvoicingOn();
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::RechargeCustomers, Permission::SetCustomerPricing));

    $dialog = rechargeDialog($this->account)
        ->assertSeeHtml('wire:model.live.blur="adjust_price"')
        ->set('adjust_price', '400')
        ->assertSee('472.00')
        ->call('recharge')
        ->assertHasErrors('adjust_reason');

    $dialog->set('adjust_reason', 'Old rate')->call('recharge')->assertHasNoErrors();

    expect(Invoice::query()->sole()->total)->toBe('472.00');
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
