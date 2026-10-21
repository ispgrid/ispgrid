<?php

use App\Actions\Billing\IssueInvoice;
use App\Enums\AdvanceRenewalStatus;
use App\Enums\InvoiceKind;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Payments\QuickPay;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    turnQuickPayOn('7');
    $this->plan = Plan::factory()->create(['price' => 500, 'validity' => 30]);
});

function quickPayCustomer(array $attributes = []): Account
{
    return Account::factory()->create(['username' => 'kushal', 'mobile' => '9876543210', 'plan_id' => test()->plan->id, 'expiry' => now()->addDays(20), ...$attributes]);
}

function owe(Account $account, string $price): void
{
    app(IssueInvoice::class)->handle($account, billingLines($price), false, InvoiceKind::Manual, now());
}

it('finds a customer by username and shows only the masked name and the dues', function () {
    $account = quickPayCustomer();
    owe($account, '100');
    owe($account, '200');

    $quotes = app(QuickPay::class)->find(' kushal ');

    expect($quotes)->toHaveCount(1)
        ->and($quotes[0]->accountId)->toBe($account->id)
        ->and($quotes[0]->maskedUsername)->toBe('ku***al')
        ->and($quotes[0]->dues)->toBe('354.00')
        ->and($quotes[0]->renewal)->toBeNull()
        ->and($quotes[0]->total)->toBe('354.00');
});

it('finds every customer on a mobile number, written any usual way', function () {
    $first = quickPayCustomer();
    $second = quickPayCustomer(['username' => 'kushal2', 'mobile' => '+91 98765 43210']);
    quickPayCustomer(['username' => 'other', 'mobile' => '9999999999']);
    owe($first, '100');
    owe($second, '100');

    expect(app(QuickPay::class)->find('09876543210'))->toHaveCount(2)
        ->and(app(QuickPay::class)->find('+919876543210'))->toHaveCount(2);
});

it('finds nothing for an unknown name, nothing to pay, a deleted or a Terminated customer', function () {
    quickPayCustomer();
    $deleted = quickPayCustomer(['username' => 'gone']);
    owe($deleted, '100');
    $deleted->delete();
    $terminated = quickPayCustomer(['username' => 'ended', 'status_id' => 4]);
    owe($terminated, '100');

    foreach (['nobody', 'kushal', 'gone', 'ended', ''] as $input) {
        expect(app(QuickPay::class)->find($input))->toBe([]);
    }
});

it('offers a renewal from N days before the expiry, as an advance renewal', function () {
    $account = quickPayCustomer(['expiry' => now()->addDays(7)]);

    $quote = app(QuickPay::class)->find('kushal')[0];

    expect($quote->dues)->toBe('0.00')
        ->and($quote->renewal->kind)->toBe('advance')
        ->and($quote->renewal->planId)->toBe($this->plan->id)
        ->and($quote->renewal->price)->toBe('590.00')
        ->and($quote->renewal->expiry->format('Y-m-d H:i'))->toBe($account->expiry->copy()->addDays(30)->format('Y-m-d H:i'))
        ->and($quote->total)->toBe('590.00');

    $account->update(['expiry' => now()->addDays(7)->addMinute()]);
    expect(app(QuickPay::class)->find('kushal'))->toBe([]);
});

it('offers a recharge from now once the plan has expired, also for an Inactive customer', function () {
    quickPayCustomer(['expiry' => now()->subDays(3), 'status_id' => 2]);

    $quote = app(QuickPay::class)->find('kushal')[0];

    expect($quote->renewal->kind)->toBe('recharge')
        ->and($quote->renewal->expiry->format('Y-m-d H:i'))->toBe('2026-11-20 10:00');
});

it('adds the renewal to the dues', function () {
    $account = quickPayCustomer(['expiry' => now()->subDay()]);
    owe($account, '100');

    $quote = app(QuickPay::class)->find('kushal')[0];

    expect($quote->dues)->toBe('118.00')->and($quote->total)->toBe('708.00');
});

it('offers no renewal when it is off, or to a Suspended, Free, first-login or already renewed customer', function (array $setup) {
    $account = quickPayCustomer(['expiry' => now()->subDay(), ...$setup['account'] ?? []]);
    ($setup['then'] ?? fn () => null)($account);

    expect(app(QuickPay::class)->find('kushal'))->toBe([]);
})->with([
    'renewal off' => [['then' => fn () => AppSetting::put('billing.quick_pay_renewal_days', '')]],
    'suspended' => [['account' => ['status_id' => 3]]],
    'free' => [['account' => ['billing_free' => true]]],
    'first-login plan' => [['then' => fn (Account $account) => $account->plan->update(['hotspot_starts_at_first_login' => true])]],
    'no plan' => [['account' => ['plan_id' => null]]],
    'plan deleted' => [['then' => fn (Account $account) => $account->plan->delete()]],
    'renewal waiting' => [['then' => function (Account $account) {
        $account->update(['expiry' => now()->addDay()]);
        AdvanceRenewal::factory()->create(['account_id' => $account->id, 'status' => AdvanceRenewalStatus::Waiting]);
    }]],
]);

it('masks short and long usernames', function (string $username, string $masked) {
    expect(QuickPay::mask($username))->toBe($masked);
})->with([
    ['ab', 'a***'],
    ['abcd', 'a***'],
    ['abcde', 'ab***de'],
    ['kushalgupta', 'ku*******ta'],
]);
