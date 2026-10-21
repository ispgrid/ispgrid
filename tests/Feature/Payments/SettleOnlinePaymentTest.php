<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RecordPayment;
use App\Actions\Customers\RechargeAccount;
use App\Actions\Payments\SettleOnlinePayment;
use App\Actions\Payments\StartOnlinePayment;
use App\Billing\PaymentInput;
use App\Enums\AdvanceRenewalStatus;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\GatewayUnavailable;
use App\Exceptions\NothingToPay;
use App\Exceptions\TooManyPaymentAttempts;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\AppSetting;
use App\Models\GatewaySetting;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Recharge;
use App\Payments\GatewayResult;
use App\Payments\GatewayStatus;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\Fakes\FakeGateway;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    turnQuickPayOn('7');
    $this->plan = Plan::factory()->create(['price' => 500, 'validity' => 30]);
    $this->account = Account::factory()->create(['username' => 'kushal', 'plan_id' => $this->plan->id, 'expiry' => now()->addDays(20)]);
});

function startPayment(Account $account): OnlinePayment
{
    return app(StartOnlinePayment::class)->handle($account->id, 'fake')->payment;
}

function settle(OnlinePayment $payment): OnlinePayment
{
    return app(SettleOnlinePayment::class)->handle($payment->public_id);
}

function quickPayInvoice(Account $account, string $price): Invoice
{
    return app(IssueInvoice::class)->handle($account, billingLines($price), false, InvoiceKind::Manual, now());
}

it('starts a payment for what the customer owes and sends them to the gateway', function () {
    quickPayInvoice($this->account, '100');

    $started = app(StartOnlinePayment::class)->handle($this->account->id, 'fake');

    expect($started->payment)
        ->status->toBe(OnlinePaymentStatus::Pending)
        ->amount->toBe('118.00')
        ->dues_amount->toBe('118.00')
        ->renewal_amount->toBe('0.00')
        ->currency->toBe('INR')
        ->and(strlen($started->payment->public_id))->toBe(26)
        ->and($started->start->kind)->toBe('form')
        ->and($started->start->fields['order'])->toBe($started->payment->public_id)
        ->and($started->start->fields['return'])->toBe(route('pay.return', 'fake'));
});

it('pays the dues into the oldest invoices first', function () {
    $older = quickPayInvoice($this->account, '100');
    $this->travel(1)->hours();
    $newer = quickPayInvoice($this->account, '200');
    $payment = startPayment($this->account);
    FakeGateway::pay($payment);

    $settled = settle($payment);

    $payments = Payment::query()->orderBy('id')->get();
    expect($settled->status)->toBe(OnlinePaymentStatus::Paid)
        ->and($settled->settled_at)->not->toBeNull()
        ->and($settled->gateway_txn_id)->toBe('TXN'.$payment->id)
        ->and($payments->pluck('invoice_id')->all())->toBe([$older->id, $newer->id])
        ->and($payments->pluck('amount')->all())->toBe(['118.00', '236.00'])
        ->and($payments->first()->method->name)->toBe('Online (Test gateway)')
        ->and($payments->first()->reference)->toBe('TXN'.$payment->id)
        ->and($payments->first()->user_id)->toBeNull()
        ->and($older->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($newer->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Activity::query()->where('event', 'online-payment')->sole()->subject_id)->toBe($this->account->id);
});

it('settles once however often the return, the webhook and the check ask', function () {
    quickPayInvoice($this->account, '100');
    $payment = startPayment($this->account);
    FakeGateway::pay($payment);

    settle($payment);
    settle($payment);
    settle($payment);

    expect(Payment::query()->count())->toBe(1)
        ->and(FakeGateway::$asked)->toHaveCount(1);
});

it('renews an expired customer with a recharge, its invoice and the payment', function () {
    $this->account->update(['expiry' => now()->subDays(2), 'status_id' => 2]);
    $payment = startPayment($this->account);
    FakeGateway::pay($payment);

    expect($payment->renewal_kind)->toBe('recharge')->and($payment->renewal_amount)->toBe('590.00');

    settle($payment);

    $account = $this->account->fresh();
    $recharge = Recharge::query()->sole();
    expect($account->expiry->format('Y-m-d H:i'))->toBe('2026-11-20 10:00')
        ->and($account->status_id->value)->toBe(1)
        ->and($recharge->invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($recharge->invoice->payments()->sole()->method->gateway)->toBe('fake')
        ->and($payment->fresh()->status)->toBe(OnlinePaymentStatus::Paid);
});

it('books an advance renewal for a customer whose plan has not ended yet', function () {
    $this->account->update(['expiry' => now()->addDays(3)]);
    quickPayInvoice($this->account, '100');
    $payment = startPayment($this->account);
    FakeGateway::pay($payment);

    expect($payment->renewal_kind)->toBe('advance')->and($payment->amount)->toBe('708.00');

    settle($payment);

    $renewal = AdvanceRenewal::query()->sole();
    expect($renewal->status)->toBe(AdvanceRenewalStatus::Waiting)
        ->and($renewal->expiry->format('Y-m-d H:i'))->toBe('2026-11-23 10:00')
        ->and($renewal->invoice->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::query()->count())->toBe(2)
        ->and($payment->fresh()->status)->toBe(OnlinePaymentStatus::Paid);
});

it('records nothing when the gateway took another amount', function () {
    quickPayInvoice($this->account, '100');
    $payment = startPayment($this->account);
    FakeGateway::pay($payment, '1.00');

    $settled = settle($payment);

    expect($settled->status)->toBe(OnlinePaymentStatus::NeedsAttention)
        ->and($settled->note)->toContain('1.00')
        ->and(Payment::query()->count())->toBe(0);
});

it('flags what no longer fits after staff took cash meanwhile', function () {
    $invoice = quickPayInvoice($this->account, '100');
    $payment = startPayment($this->account);
    app(RecordPayment::class)->handle($invoice->id, new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, '118', null, now(), null));
    FakeGateway::pay($payment);

    $settled = settle($payment);

    expect($settled->status)->toBe(OnlinePaymentStatus::NeedsAttention)
        ->and($settled->note)->toContain('118.00')
        ->and(Payment::query()->whereHas('method', fn ($query) => $query->whereNotNull('gateway'))->count())->toBe(0);
});

it('flags the renewal when it can no longer be booked', function () {
    $this->account->update(['expiry' => now()->subDay()]);
    $payment = startPayment($this->account);
    app(RechargeAccount::class)->handle($this->account->id, $this->plan->id, now()->addDays(30), '590', null);
    FakeGateway::pay($payment);

    $settled = settle($payment);

    expect($settled->status)->toBe(OnlinePaymentStatus::NeedsAttention)
        ->and($settled->note)->toContain('590.00')
        ->and(Recharge::query()->count())->toBe(1);
});

it('marks a refused payment failed and leaves a pending one pending', function () {
    quickPayInvoice($this->account, '100');
    $refused = startPayment($this->account);
    $waiting = startPayment($this->account);
    FakeGateway::$results[$refused->public_id] = new GatewayResult(GatewayStatus::Failed, providerStatus: 'TXN_FAILURE');

    expect(settle($refused)->status)->toBe(OnlinePaymentStatus::Failed)
        ->and(settle($waiting)->status)->toBe(OnlinePaymentStatus::Pending)
        ->and(Payment::query()->count())->toBe(0);
});

it('checks pending payments in the background and gives up after a day', function () {
    quickPayInvoice($this->account, '100');
    $old = startPayment($this->account);
    $this->travel(10)->minutes();
    $fresh = startPayment($this->account);
    $this->travel(1)->minutes();
    FakeGateway::pay($old);
    FakeGateway::pay($fresh);

    $this->artisan('payments:check-online')->assertSuccessful();

    expect($old->fresh()->status)->toBe(OnlinePaymentStatus::Paid)
        ->and($fresh->fresh()->status)->toBe(OnlinePaymentStatus::Pending);

    $this->travel(25)->hours();
    FakeGateway::$results = [];
    $this->artisan('payments:check-online')->assertSuccessful();

    expect($fresh->fresh()->status)->toBe(OnlinePaymentStatus::Failed);
});

it('still settles a payment after quick pay and the gateway are turned off', function () {
    quickPayInvoice($this->account, '100');
    $payment = startPayment($this->account);
    AppSetting::put('billing.quick_pay', '0');
    GatewaySetting::query()->update(['enabled' => false]);
    FakeGateway::pay($payment);

    expect(settle($payment)->status)->toBe(OnlinePaymentStatus::Paid);
});

it('refuses to start with nothing to pay, a gateway that is off, or after five tries in an hour', function () {
    expect(fn () => startPayment($this->account))->toThrow(NothingToPay::class);

    quickPayInvoice($this->account, '100');
    GatewaySetting::query()->update(['enabled' => false]);
    expect(fn () => startPayment($this->account))->toThrow(GatewayUnavailable::class)
        ->and(OnlinePayment::query()->count())->toBe(0);

    GatewaySetting::query()->update(['enabled' => true]);
    foreach (range(1, 5) as $try) {
        startPayment($this->account);
    }
    expect(fn () => startPayment($this->account))->toThrow(TooManyPaymentAttempts::class);
});

it('marks the attempt failed when the gateway refuses to start it', function () {
    quickPayInvoice($this->account, '100');
    FakeGateway::$refuseStart = true;

    expect(fn () => startPayment($this->account))->toThrow(GatewayUnavailable::class)
        ->and(OnlinePayment::query()->sole()->status)->toBe(OnlinePaymentStatus::Failed);
});

it('still records the dues of a customer deleted while paying, and leaves the renewal to staff', function () {
    $this->account->update(['expiry' => now()->addDays(3)]);
    quickPayInvoice($this->account, '100');
    $payment = startPayment($this->account);
    $this->account->delete();
    FakeGateway::pay($payment);

    $settled = settle($payment);

    expect($settled->status)->toBe(OnlinePaymentStatus::NeedsAttention)
        ->and($settled->note)->toContain('590.00')
        ->and(Payment::query()->count())->toBe(1)
        ->and(AdvanceRenewal::query()->count())->toBe(0);
});
