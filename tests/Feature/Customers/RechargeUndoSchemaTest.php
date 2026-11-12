<?php

use App\Enums\RechargeKind;
use App\Models\Account;
use App\Models\Recharge;
use App\Models\Role;

it('gives Cancel recharges to roles that can cancel invoices', function () {
    $accounts = Role::create(['name' => 'Accounts']);
    $accounts->givePermissionTo(['view-customers', 'view-invoices', 'cancel-invoices']);
    $desk = Role::create(['name' => 'Front desk']);
    $desk->givePermissionTo(['view-customers', 'recharge-customers']);

    (require database_path('migrations/2026_11_12_000000_add_recharge_undo_and_amount_discounts.php'))->grant();

    expect($accounts->fresh()->permissions->pluck('name')->all())->toContain('cancel-recharges')
        ->and($desk->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['recharge-customers', 'view-customers']);
});

it('counts only recharges that are not cancelled and not plan switches', function () {
    $kept = Recharge::factory()->create();
    Recharge::factory()->create(['cancelled_at' => now()]);
    Recharge::factory()->create(['kind' => RechargeKind::SwitchPlan, 'amount' => 0]);

    expect(Recharge::query()->counted()->pluck('id')->all())->toBe([$kept->id]);
});

it('starts a new customer on a percent discount of nothing', function () {
    $account = Account::factory()->create()->refresh();

    expect($account->discount_type)->toBe('percent')
        ->and($account->discount_amount)->toBe('0.00');
});
