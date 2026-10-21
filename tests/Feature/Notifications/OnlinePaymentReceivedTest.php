<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Payments\SettleOnlinePayment;
use App\Actions\Payments\StartOnlinePayment;
use App\Enums\InvoiceKind;
use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Models\Payment;
use App\Models\Plan;
use App\Notifications\NotificationRegistry;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeGateway;
use Tests\Feature\Notifications\FakeProvider;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class]);
    AppSetting::put('phone_digits', '10');
    app(NotificationRegistry::class)->registerProvider(FakeProvider::class);
    NotificationProvider::query()->create(['provider' => 'fake', 'enabled' => true, 'settings' => ['token' => 'tok12345']]);
    Queue::fake();
    turnQuickPayOn('7');
    $this->account = Account::factory()->create(['mobile' => '9876543210', 'plan_id' => Plan::factory()->create(['price' => 500]), 'expiry' => now()->addDays(20)]);
    $this->invoice = app(IssueInvoice::class)->handle($this->account, billingLines('100'), false, InvoiceKind::Manual, now());
});

it('confirms an online payment once, with its amount, receipt and what is still due', function () {
    $rule = NotificationRule::factory()->enabled()->create(['trigger' => 'online_payment_received', 'trigger_settings' => [],
        'values' => ['payment_amount', 'receipt_number', 'invoice_number', 'payment_method', 'balance_due']]);
    $payment = app(StartOnlinePayment::class)->handle($this->account->id, 'fake')->payment;
    FakeGateway::pay($payment);

    app(SettleOnlinePayment::class)->handle($payment->public_id);
    app(SettleOnlinePayment::class)->handle($payment->public_id);

    $log = NotificationLog::query()->sole();
    expect($log->once_key)->toBe("rule:{$rule->id}:online-payment:{$payment->public_id}")
        ->and($log->values)->toBe(['118.00', 'RCPT-'.Payment::query()->sole()->id, $this->invoice->number, 'Online (Test gateway)', '0.00']);
    Queue::assertPushed(SendNotification::class, 1);
});

it('sends nothing when the gateway took another amount', function () {
    NotificationRule::factory()->enabled()->create(['trigger' => 'online_payment_received', 'trigger_settings' => [], 'values' => ['payment_amount']]);
    $payment = app(StartOnlinePayment::class)->handle($this->account->id, 'fake')->payment;
    FakeGateway::pay($payment, '1.00');

    app(SettleOnlinePayment::class)->handle($payment->public_id);

    expect(NotificationLog::query()->count())->toBe(0);
});

it('offers the payment values on the rule form', function () {
    $trigger = app(NotificationRegistry::class)->trigger('online_payment_received');

    expect($trigger::label())->toBe('Online payment received')
        ->and($trigger::placeholders())->toContain('payment_amount', 'receipt_number', 'balance_due', 'customer_name');
});
