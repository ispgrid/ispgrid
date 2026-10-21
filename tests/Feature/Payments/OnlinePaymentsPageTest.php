<?php

use App\Enums\OnlinePaymentStatus;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\OnlinePayment;
use App\Support\ActivityTimeline;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Fakes\FakeGateway;

beforeEach(function () {
    turnQuickPayOn();
    $this->account = Account::factory()->create(['username' => 'kushal']);
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices, Permission::RecordPayments));
});

it('lists online payments for staff who see invoices, filtered by status', function () {
    OnlinePayment::factory()->create(['account_id' => $this->account->id, 'status' => OnlinePaymentStatus::Paid, 'gateway_txn_id' => 'TXN-PAID']);
    OnlinePayment::factory()->create(['account_id' => $this->account->id, 'status' => OnlinePaymentStatus::Failed, 'gateway_txn_id' => 'TXN-FAILED']);

    $this->get(route('online-payments.index'))->assertOk()->assertSee('kushal')->assertSee('TXN-PAID')->assertSee('Test gateway');

    Livewire::test('pages::payments.online')->set('status', 'failed')->assertSee('TXN-FAILED')->assertDontSee('TXN-PAID');

    $this->actingAs(staffHolding(Permission::ViewCustomers));
    $this->get(route('online-payments.index'))->assertForbidden();
});

it('asks the gateway again about a pending payment', function () {
    $payment = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'amount' => '0.00', 'dues_amount' => '0.00']);
    FakeGateway::pay($payment);

    Livewire::test('pages::payments.online')->call('checkNow', $payment->id)->assertSee(__('Paid'));

    expect($payment->fresh()->status)->toBe(OnlinePaymentStatus::Paid);
});

it('closes a payment that needs attention with what was done, and logs it', function () {
    $payment = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'status' => OnlinePaymentStatus::NeedsAttention, 'note' => 'Not used: INR 118.00']);

    Livewire::test('pages::payments.online')
        ->call('openHandle', $payment->id)
        ->call('markHandled')
        ->assertHasErrors('handled_note')
        ->set('handled_note', 'Refunded in Paytm')
        ->call('markHandled')
        ->assertHasNoErrors()
        ->assertSee('Refunded in Paytm')
        ->assertDontSee(__('Mark handled').'</button>', false);

    $entry = Activity::query()->where('event', 'online-payment-handled')->sole();
    expect($payment->fresh()->handled_note)->toBe('Refunded in Paytm')
        ->and($entry->subject_id)->toBe($this->account->id)
        ->and(ActivityTimeline::entry($entry, 'd/m/Y')['sentence'])->toContain('Refunded in Paytm');
});

it('lets only staff who record payments act', function () {
    $pending = OnlinePayment::factory()->create(['account_id' => $this->account->id]);
    $flagged = OnlinePayment::factory()->create(['account_id' => $this->account->id, 'status' => OnlinePaymentStatus::NeedsAttention]);
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices));

    Livewire::test('pages::payments.online')
        ->assertDontSee(__('Check now'))
        ->call('checkNow', $pending->id)->assertForbidden();
    Livewire::test('pages::payments.online')->call('openHandle', $flagged->id)->assertForbidden();
});
