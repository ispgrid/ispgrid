<?php

use App\Actions\Customers\RechargeAccount;
use App\Billing\BillingSettings;
use App\Billing\ChargeOptions;
use App\Billing\PaymentInput;
use App\Billing\RechargeInvoice;
use App\Billing\RechargePrice;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\RechargeKind;
use App\Exceptions\PaymentExceedsBalance;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    $this->actingAs(User::factory()->create());
    $this->plan = Plan::factory()->create(['name' => 'Home 30', 'price' => 500, 'validity' => 30, 'upload' => '6M', 'download' => '30M']);
    $this->cash = PaymentMethod::query()->where('name', 'Cash')->sole();
});

function rechargeNow(Account $account, Plan $plan, string $amount = '500', ?ChargeOptions $charge = null, RechargeKind $kind = RechargeKind::Recharge): Recharge
{
    return app(RechargeAccount::class)->handle($account->id, $plan->id, Carbon::parse('2026-11-20 10:00'), $amount, null, null, $kind, null, $charge ?? new ChargeOptions);
}

function cashPayment(string $amount): PaymentInput
{
    return new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, $amount, null, now(), null);
}

it('a paid recharge makes a paid invoice', function () {
    turnInvoicingOn();
    $account = Account::factory()->create(['expiry' => '2026-10-20 10:00:00']);

    $recharge = rechargeNow($account, $this->plan, charge: new ChargeOptions(payment: cashPayment('590.00')));

    $invoice = Invoice::query()->sole();
    expect($invoice)
        ->status->toBe(InvoiceStatus::Paid)
        ->kind->toBe(InvoiceKind::Recharge)
        ->recharge_id->toBe($recharge->id)
        ->total->toBe('590.00')
        ->and($recharge->fresh())
        ->invoice_id->toBe($invoice->id)
        ->amount->toBe('590.00')
        ->and($invoice->lines()->sole())
        ->description->toBe('Home 30')
        ->sub_description->toBe('Charges for period 21 Oct 2026 to 20 Nov 2026 · 30 Mbps down / 6 Mbps up')
        ->item_code->toBe('998422');
});

it('a recharge without payment leaves the invoice Unpaid', function () {
    turnInvoicingOn();
    $account = Account::factory()->create(['payment_terms_days' => 7]);

    rechargeNow($account, $this->plan);

    expect(Invoice::query()->sole())
        ->status->toBe(InvoiceStatus::Unpaid)
        ->balance->toBe('590.00')
        ->due_date->format('Y-m-d')->toBe('2026-10-28');
});

it('charges the plan price for Change plan', function () {
    turnInvoicingOn();
    $account = Account::factory()->create();

    $recharge = rechargeNow($account, $this->plan, '1', kind: RechargeKind::ChangePlan);

    expect($recharge->fresh()->amount)->toBe('590.00')
        ->and(Invoice::query()->sole()->total)->toBe('590.00');
});

it('charges the customer discount, and an adjusted price with its reason', function () {
    turnInvoicingOn();
    $account = Account::factory()->create(['discount_percent' => 50]);

    rechargeNow($account, $this->plan);
    rechargeNow($account, $this->plan, charge: new ChargeOptions(adjustedPrice: '400', adjustReason: 'Old rate'));

    [$first, $second] = Invoice::query()->orderBy('id')->get()->all();
    expect($first->total)->toBe('295.00')
        ->and($second->total)->toBe('236.00')
        ->and(Activity::query()->where('event', 'recharged')->latest('id')->first()->attribute_changes['attributes']['adjust_reason'])->toBe('Old rate');
});

it('a Free customer\'s auto renewal makes no invoice', function () {
    turnInvoicingOn();
    $account = Account::factory()->create(['plan_id' => $this->plan->id, 'billing_free' => true, 'auto_renew' => true, 'expiry' => '2026-10-21 10:05:00']);

    $this->artisan('customers:renewals')->assertSuccessful();

    expect(Recharge::query()->sole())
        ->account_id->toBe($account->id)
        ->amount->toBe('0.00')
        ->invoice_id->toBeNull()
        ->and(Invoice::query()->count())->toBe(0);
});

it('auto renewal makes an Unpaid invoice due by the terms', function () {
    turnInvoicingOn();
    Account::factory()->create(['plan_id' => $this->plan->id, 'auto_renew' => true, 'payment_terms_days' => 15, 'expiry' => '2026-10-21 10:05:00']);

    $this->artisan('customers:renewals')->assertSuccessful();

    expect(Invoice::query()->sole())
        ->status->toBe(InvoiceStatus::Unpaid)
        ->period_from->format('Y-m-d H:i')->toBe('2026-10-21 10:05')
        ->due_date->format('Y-m-d')->toBe('2026-11-05')
        ->and(Recharge::query()->sole()->amount)->toBe('590.00');
});

it('with invoicing off a recharge makes no invoice and records the discounted amount', function () {
    Account::factory()->create(['plan_id' => $this->plan->id, 'discount_percent' => 50, 'auto_renew' => true, 'expiry' => '2026-10-21 10:05:00']);

    $this->artisan('customers:renewals')->assertSuccessful();

    expect(Recharge::query()->sole()->amount)->toBe('250.00')
        ->and(Invoice::query()->count())->toBe(0);
});

it('keeps the amount staff typed when invoicing is off, and 0 for a Free customer', function () {
    $paid = Account::factory()->create();
    $free = Account::factory()->create(['billing_free' => true]);

    expect(rechargeNow($paid, $this->plan, '450')->amount)->toBe('450.00')
        ->and(rechargeNow($free, $this->plan, '450')->amount)->toBe('0.00')
        ->and(Invoice::query()->count())->toBe(0);
});

it('skips Hotspot recharges when Invoice Hotspot recharges is off', function () {
    turnInvoicingOn();
    AppSetting::put('billing.invoice_hotspot', '0');
    $account = Account::factory()->hotspot()->create();

    $recharge = rechargeNow($account, $this->plan, '500');

    expect($recharge->invoice_id)->toBeNull()
        ->and($recharge->amount)->toBe('500.00')
        ->and(Invoice::query()->count())->toBe(0);
});

it('bill period starts at the old expiry while the plan runs', function () {
    turnInvoicingOn();
    $running = Account::factory()->create(['expiry' => '2026-10-25 08:00:00']);
    $ended = Account::factory()->create(['expiry' => '2026-10-01 08:00:00']);

    rechargeNow($running, $this->plan);
    rechargeNow($ended, $this->plan);

    expect(Invoice::query()->where('account_id', $running->id)->sole()->period_from->format('Y-m-d H:i'))->toBe('2026-10-25 08:00')
        ->and(Invoice::query()->where('account_id', $ended->id)->sole()->period_from->format('Y-m-d H:i'))->toBe('2026-10-21 10:00');
});

it('bills a plan change from now, since its validity starts again', function () {
    turnInvoicingOn();
    $account = Account::factory()->create(['expiry' => '2026-11-15 08:00:00']);

    rechargeNow($account, $this->plan, kind: RechargeKind::ChangePlan);

    $invoice = Invoice::query()->sole();
    expect($invoice->period_from->format('Y-m-d H:i'))->toBe('2026-10-21 10:00')
        ->and($invoice->lines()->sole()->sub_description)->toStartWith('Charges for period 21 Oct 2026 to 20 Nov 2026');
});

it('bills from now when staff set an expiry before the old one', function () {
    turnInvoicingOn();
    $account = Account::factory()->create(['expiry' => '2026-12-31 08:00:00']);

    rechargeNow($account, $this->plan);

    expect(Invoice::query()->sole()->period_from->format('Y-m-d H:i'))->toBe('2026-10-21 10:00');
});

it('describes a first-login validity instead of a bill period', function () {
    turnInvoicingOn();
    turnHotspotOn();
    $plan = Plan::factory()->create(['name' => 'Day pass', 'price' => 50, 'validity' => 3, 'validity_unit' => 'hours', 'hotspot_starts_at_first_login' => true, 'upload' => '2M', 'download' => '2M']);
    $account = Account::factory()->hotspot()->create(['expiry' => null]);

    app(RechargeAccount::class)->handle($account->id, $plan->id, null, '50', null);

    $invoice = Invoice::query()->sole();
    expect($invoice->period_from)->toBeNull()
        ->and($invoice->validity_text)->toBe('Validity: 3 hours from the first login')
        ->and($invoice->lines()->sole()->sub_description)->toBe('Validity: 3 hours from the first login · 2 Mbps down / 2 Mbps up');
});

it('two recharges in one second get consecutive numbers', function () {
    turnInvoicingOn();
    $first = Account::factory()->create();
    $second = Account::factory()->create();

    rechargeNow($first, $this->plan);
    rechargeNow($second, $this->plan);

    expect(Invoice::query()->orderBy('id')->pluck('number')->all())->toBe(['INV-1', 'INV-2']);
});

it('rolls back the recharge when the invoice fails', function () {
    turnInvoicingOn();
    $account = Account::factory()->create(['expiry' => '2026-10-25 08:00:00']);

    expect(fn () => rechargeNow($account, $this->plan, charge: new ChargeOptions(payment: cashPayment('9999'))))
        ->toThrow(PaymentExceedsBalance::class);

    expect(Recharge::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0)
        ->and($account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2026-10-25 08:00');
});

it('works out the dialog amount the way the recharge records it', function () {
    $account = Account::factory()->create(['discount_percent' => 10]);
    $free = Account::factory()->create(['billing_free' => true]);

    expect(RechargePrice::amount($account, $this->plan, BillingSettings::current()))->toBe('450.00')
        ->and(RechargePrice::amount($free, $this->plan, BillingSettings::current()))->toBe('0.00');

    turnInvoicingOn();

    expect(RechargePrice::amount($account, $this->plan, BillingSettings::current()))->toBe('531.00')
        ->and(RechargeInvoice::draft($account, $this->plan, BillingSettings::current(), '100', null, null, null)->total)->toBe('106.20');
});
