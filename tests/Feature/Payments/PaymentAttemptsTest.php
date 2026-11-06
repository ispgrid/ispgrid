<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Payments\StartOnlinePayment;
use App\Enums\InvoiceKind;
use App\Enums\OnlinePaymentStatus;
use App\Exceptions\TooManyPaymentAttempts;
use App\Models\Account;
use App\Models\OnlinePayment;
use App\Models\Plan;
use App\Payments\StartedPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeGateway;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    turnQuickPayOn('7');
    $this->account = Account::factory()->create();
    app(IssueInvoice::class)->handle($this->account, billingLines('100'), false, InvoiceKind::Manual, now());
});

function payFrom(Account $account, string $ip): StartedPayment
{
    return app(StartOnlinePayment::class)->handle($account->id, 'fake', $ip);
}

it('reuses the open attempt when the customer presses Pay again', function () {
    $first = payFrom($this->account, '203.0.113.5');
    $this->travel(5)->minutes();
    $again = payFrom($this->account, '203.0.113.5');

    expect($again->payment->public_id)->toBe($first->payment->public_id)
        ->and($again->start)->toEqual($first->start)
        ->and(FakeGateway::$starts)->toBe(1)
        ->and(OnlinePayment::query()->count())->toBe(1);
});

it('reuses an open attempt that renews the plan', function () {
    $plan = Plan::factory()->create(['price' => 500, 'validity' => 30]);
    $renewing = Account::factory()->create(['plan_id' => $plan->id, 'expiry' => now()->addDays(3)]);

    $first = payFrom($renewing, '203.0.113.5');
    $again = payFrom($renewing, '203.0.113.5');

    expect($first->payment->renewal_plan_id)->toBe($plan->id)
        ->and($again->payment->public_id)->toBe($first->payment->public_id);
});

it('keeps the gateway start encrypted', function () {
    $started = payFrom($this->account, '203.0.113.5');

    expect(DB::table('online_payments')->value('gateway_start'))->not->toBeNull()->not->toContain($started->payment->public_id);
});

it('opens a new attempt once the open one is 10 minutes old or the amount changed', function () {
    $first = payFrom($this->account, '203.0.113.5');

    $this->travel(11)->minutes();
    $later = payFrom($this->account, '203.0.113.5');

    app(IssueInvoice::class)->handle($this->account, billingLines('50'), false, InvoiceKind::Manual, now());
    $more = payFrom($this->account, '203.0.113.5');

    expect($later->payment->public_id)->not->toBe($first->payment->public_id)
        ->and($more->payment->public_id)->not->toBe($later->payment->public_id)
        ->and((string) $more->payment->amount)->not->toBe((string) $later->payment->amount);
});

it('counts tries per address, so one address cannot block the customer', function () {
    foreach (range(1, 5) as $try) {
        payFrom($this->account, '203.0.113.5');
        $this->travel(11)->minutes();
    }

    expect(fn () => payFrom($this->account, '203.0.113.5'))->toThrow(TooManyPaymentAttempts::class)
        ->and(payFrom($this->account, '198.51.100.7')->payment->status)->toBe(OnlinePaymentStatus::Pending);
});

it('stops every address after 30 tries in an hour', function () {
    OnlinePayment::factory()->count(30)->sequence(fn ($sequence) => ['client_ip' => '192.0.2.'.$sequence->index])
        ->create(['account_id' => $this->account->id, 'status' => OnlinePaymentStatus::Failed, 'created_at' => now()->subMinutes(30)]);

    expect(fn () => payFrom($this->account, '198.51.100.7'))->toThrow(TooManyPaymentAttempts::class);
});
