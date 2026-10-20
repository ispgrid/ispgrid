<?php

use App\Models\Account;
use App\Models\AppSetting;
use App\Models\PaymentMethod;
use App\Models\Role;
use Illuminate\Support\Facades\Schema;

it('creates the billing tables and seeds the payment methods', function () {
    expect(PaymentMethod::query()->orderBy('position')->pluck('name')->all())
        ->toBe(['Cash', 'UPI', 'Bank transfer', 'Cheque'])
        ->and(Schema::hasColumns('accounts', ['billing_free', 'discount_percent', 'payment_terms_days', 'billing_name', 'billing_address_id', 'place_of_supply']))->toBeTrue()
        ->and(Schema::hasColumns('plans', ['charge_tax', 'round_off', 'item_code']))->toBeTrue()
        ->and(Schema::hasColumn('recharges', 'invoice_id'))->toBeTrue()
        ->and(Schema::hasColumn('advance_renewals', 'invoice_id'))->toBeTrue();
});

it('grants the new boxes only to roles that had the access they replace', function () {
    $settings = Role::create(['name' => 'Office manager']);
    $settings->givePermissionTo(['manage-app-settings']);
    $desk = Role::create(['name' => 'Front desk']);
    $desk->givePermissionTo(['view-customers', 'recharge-customers']);
    $both = Role::create(['name' => 'Accounts']);
    $both->givePermissionTo(['view-customers', 'recharge-customers', 'manage-app-settings']);
    $other = Role::create(['name' => 'Viewer']);
    $other->givePermissionTo(['view-customers']);

    (require database_path('migrations/2026_10_22_000000_create_billing_tables.php'))->grant();

    $boxes = fn (Role $role): array => $role->fresh()->permissions->pluck('name')->sort()->values()->all();

    expect($boxes($settings))->toBe(['cancel-invoices', 'manage-app-settings', 'manage-billing', 'set-customer-pricing', 'view-sales'])
        ->and($boxes($desk))->toBe(['create-invoices', 'recharge-customers', 'record-payments', 'view-customers', 'view-invoices'])
        ->and($boxes($both))->toContain('cancel-invoices', 'manage-billing', 'record-payments')
        ->and($boxes($other))->toBe(['view-customers']);
});

it('upgrade leaves invoicing off', function () {
    $account = Account::factory()->create()->refresh();

    expect(AppSetting::valueOf('billing.enabled', '0'))->toBe('0')
        ->and($account->billing_free)->toBeFalse()
        ->and($account->discount_percent)->toBe('0.00')
        ->and($account->payment_terms_days)->toBe(0);
});
