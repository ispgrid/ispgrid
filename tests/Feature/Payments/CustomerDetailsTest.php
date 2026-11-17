<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Payments\StartOnlinePayment;
use App\Contracts\NeedsCustomerDetails;
use App\Enums\InvoiceKind;
use App\Exceptions\GatewayUnavailable;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\GatewaySetting;
use App\Models\OnlinePayment;
use App\Payments\CustomerDetails;
use App\Payments\PaymentGateways;
use Tests\Fakes\EmailGateway;
use Tests\Fakes\PhoneGateway;

beforeEach(function () {
    turnQuickPayOn('7');
    $gateways = app(PaymentGateways::class);
    $gateways->register(PhoneGateway::class);
    $gateways->register(EmailGateway::class);
    GatewaySetting::query()->create(['gateway' => 'phonegw', 'enabled' => true, 'settings' => []]);
    GatewaySetting::query()->create(['gateway' => 'emailgw', 'enabled' => true, 'settings' => []]);
});

function invoicedAccount(array $attributes): Account
{
    $account = Account::factory()->create($attributes);
    app(IssueInvoice::class)->handle($account, billingLines('100'), false, InvoiceKind::Manual, now());

    return $account;
}

it('hides a phone gateway for a customer without a usable mobile and says why', function (?string $mobile) {
    $account = Account::factory()->create(['mobile' => $mobile, 'email' => 'a@example.com']);
    $gateways = app(PaymentGateways::class);

    expect($gateways->enabledFor($account))->not->toContain('phonegw')->toContain('fake', 'emailgw')
        ->and($gateways->unavailableFor($account))->toBe(['phonegw' => 'Mobile number needed for this payment option, contact us.']);
})->with(['short' => '12345', 'empty' => null, 'blank' => '', 'landline' => '2212345678', 'landline with zero' => '0112345678']);

it('shows a phone gateway for a valid Indian mobile', function () {
    $account = Account::factory()->create(['mobile' => '98765 43210', 'email' => 'a@example.com']);

    expect(app(PaymentGateways::class)->enabledFor($account))->toContain('phonegw')
        ->and(CustomerDetails::for($account)->phone)->toBe('+919876543210')
        ->and(CustomerDetails::for($account)->nationalPhone())->toBe('9876543210');
});

it('refuses to start a hidden gateway even when the request names it', function () {
    $account = invoicedAccount(['mobile' => '12345']);

    expect(fn () => app(StartOnlinePayment::class)->handle($account->id, 'phonegw', '203.0.113.5'))->toThrow(GatewayUnavailable::class)
        ->and(OnlinePayment::query()->count())->toBe(0)
        ->and(PhoneGateway::$seen)->toBeNull();
});

it('starts a phone gateway with the customer details', function () {
    PhoneGateway::$seen = null;
    $account = invoicedAccount(['mobile' => '9876543210', 'fullname' => '', 'email' => null, 'username' => 'kushal']);

    app(StartOnlinePayment::class)->handle($account->id, 'phonegw', '203.0.113.5');

    expect(PhoneGateway::$seen?->name)->toBe('kushal')->and(PhoneGateway::$seen?->phone)->toBe('+919876543210');
});

it('falls back to the company email and hides email gateways when there is none', function () {
    $account = Account::factory()->create(['mobile' => '9876543210', 'email' => null]);

    AppSetting::put('billing.company', (string) json_encode(['name' => 'Example ISP', 'country' => 'IN', 'email' => 'help@isp.example']));
    expect(CustomerDetails::for($account)->email)->toBe('help@isp.example')
        ->and(app(PaymentGateways::class)->enabledFor($account))->toContain('emailgw');

    AppSetting::put('billing.company', (string) json_encode(['name' => 'Example ISP', 'country' => 'IN']));
    expect(CustomerDetails::for($account)->email)->toBeNull()
        ->and(app(PaymentGateways::class)->enabledFor($account))->not->toContain('emailgw')
        ->and(app(PaymentGateways::class)->unavailableFor($account))->toBe(['emailgw' => 'Email address needed for this payment option, contact us.']);
});

it('shows every gateway to a customer with a mobile and email', function () {
    $account = Account::factory()->create(['mobile' => '9876543210', 'email' => 'a@example.com']);

    expect(app(PaymentGateways::class)->enabledFor($account))->toEqualCanonicalizing(['fake', 'phonegw', 'emailgw'])
        ->and(app(PaymentGateways::class)->unavailableFor($account))->toBe([])
        ->and(PhoneGateway::requiredDetails())->toBe(['phone'])
        ->and(new PhoneGateway([]))->toBeInstanceOf(NeedsCustomerDetails::class);
});

it('names the customer by full name, else username', function () {
    expect(CustomerDetails::for(Account::factory()->create(['fullname' => 'Kushal Example']))->name)->toBe('Kushal Example')
        ->and(CustomerDetails::for(Account::factory()->create(['fullname' => '', 'username' => 'kushal2']))->name)->toBe('kushal2');
});
