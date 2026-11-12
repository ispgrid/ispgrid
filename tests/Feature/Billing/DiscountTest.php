<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Customers\RechargeAccount;
use App\Billing\BillingSettings;
use App\Billing\Discount;
use App\Billing\LineInput;
use App\Billing\Pdf\InvoiceView;
use App\Billing\RechargePrice;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\Plan;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    $this->account = Account::factory()->create(['username' => 'alice']);
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::RechargeCustomers, Permission::ViewInvoices,
        Permission::CreateInvoices, Permission::CancelInvoices, Permission::SetCustomerPricing));
});

/**
 * One taxed line of this price with a fixed discount.
 */
function amountOffLine(string $price, string $amount): LineInput
{
    return new LineInput('Plan', null, null, '1', $price, '0', true, discountAmount: $amount);
}

it('takes a percent or a fixed amount off, never more than the line', function (Discount $discount, string $taken) {
    expect($discount->takenOff('500.00', 2))->toBe($taken);
})->with([
    'percent' => [new Discount(Discount::PERCENT, '10'), '50.00'],
    'amount' => [new Discount(Discount::AMOUNT, '100'), '100.00'],
    'amount above the line' => [new Discount(Discount::AMOUNT, '700'), '500.00'],
    'none' => [Discount::none(), '0.00'],
]);

it('describes itself as a percent or an amount', function () {
    $money = Money::fromSettings();

    expect((new Discount(Discount::PERCENT, '10'))->describe($money))->toBe('10%')
        ->and((new Discount(Discount::AMOUNT, '100'))->describe($money))->toBe($money->format('100'))
        ->and(Discount::none()->isNone())->toBeTrue()
        ->and((new Discount(Discount::AMOUNT, '0'))->isNone())->toBeTrue();
});

it('caps a fixed discount at the line amount, so the total never goes below 0', function () {
    turnInvoicingOn('GB', 'none', []);
    $draft = IssueInvoice::draft($this->account, [amountOffLine('500', '700')], false, BillingSettings::current());

    expect($draft->lines[0]->discountAmount)->toBe('500.00')
        ->and($draft->total)->toBe('0.00');
});

it('works out GST on the price less a fixed discount', function () {
    turnInvoicingOn();
    $draft = IssueInvoice::draft($this->account, [amountOffLine('500', '100')], false, BillingSettings::current());

    expect($draft->taxableTotal)->toBe('400.00')
        ->and(array_map(fn ($line) => [$line->name, $line->amount], $draft->taxLines))->toBe([['CGST', '36.00'], ['SGST', '36.00']])
        ->and($draft->total)->toBe('472.00');
});

it('takes a fixed discount off a price that includes tax before taking the tax out', function () {
    turnInvoicingOn();
    AppSetting::put('billing.prices_include_tax', '1');
    $draft = IssueInvoice::draft($this->account, [amountOffLine('590', '100')], false, BillingSettings::current());

    expect($draft->taxableTotal)->toBe('415.25')
        ->and($draft->total)->toBe('490.00');
});

it('prices a recharge with the customer saved amount discount', function () {
    $plan = Plan::factory()->create(['price' => 500, 'charge_tax' => true]);
    $this->account->update(['discount_type' => Discount::AMOUNT, 'discount_amount' => 100]);

    expect(RechargePrice::amount($this->account, $plan, BillingSettings::current()))->toBe('400.00');

    turnInvoicingOn();
    $recharge = app(RechargeAccount::class)->handle($this->account->id, $plan->id, Carbon::parse('2026-11-20 10:00'), '0', null);
    $line = $recharge->invoice->lines->sole();

    expect($line->discount_percent)->toBe('0.00')
        ->and($line->discount_amount)->toBe('100.00')
        ->and($recharge->amount)->toBe('472.00');
});

it('prints a fixed discount without a percent and a percent discount with it', function () {
    turnInvoicingOn();
    $money = Money::fromSettings();
    $fixed = app(IssueInvoice::class)->handle($this->account, [amountOffLine('500', '100')], false, InvoiceKind::Manual, now());
    $percent = app(IssueInvoice::class)->handle($this->account, billingLines('500', '1', '10'), false, InvoiceKind::Manual, now());

    expect(InvoiceView::for($fixed)['lines'][0]['discount'])->toBe($money->format('100'))
        ->and(InvoiceView::for($percent)['lines'][0]['discount'])->toBe($money->format('50').' (10%)');
});

it('keeps a fixed discount when an invoice is cancelled and reissued', function (array $permissions) {
    turnInvoicingOn();
    $old = app(IssueInvoice::class)->handle($this->account, [amountOffLine('500', '100')], false, InvoiceKind::Manual, now());
    $this->actingAs(staffHolding(...$permissions));

    Livewire::test('pages::invoices.show', ['invoice' => $old->id])
        ->call('openReissueInvoice', $old->id)
        ->assertSet('lines.0.discount', '0')
        ->set('reissue_reason', 'Wrong address')
        ->call('reissueInvoice')
        ->assertHasNoErrors();

    $line = Invoice::query()->where('replaces_invoice_id', $old->id)->sole()->lines->sole();
    expect($line->discount_amount)->toBe('100.00')->and($line->discount_percent)->toBe('0.00');
})->with([
    'with Set customer pricing' => [[Permission::ViewCustomers, Permission::ViewInvoices, Permission::CreateInvoices, Permission::CancelInvoices, Permission::SetCustomerPricing]],
    'without it' => [[Permission::ViewCustomers, Permission::ViewInvoices, Permission::CreateInvoices, Permission::CancelInvoices]],
]);
