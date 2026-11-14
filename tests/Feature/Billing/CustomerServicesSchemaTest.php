<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\CustomerService;
use App\Models\CustomerServiceRenewal;
use App\Models\Item;
use App\Models\Role;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-14 10:00:00'));
});

it('works out the state of a service', function () {
    $now = now();
    $static = Item::factory()->staticIp()->create();
    $plain = Item::factory()->service()->create();

    expect(CustomerService::factory()->for($static)->create(['paid_until' => $now->copy()->addDay()])->state($now))->toBe('active')
        ->and(CustomerService::factory()->for($static)->create(['paid_until' => $now->copy()->subDay(), 'expired_at' => $now])->state($now))->toBe('held')
        ->and(CustomerService::factory()->for($static)->create(['paid_until' => $now->copy()->subMinute()])->state($now))->toBe('held')
        ->and(CustomerService::factory()->for($plain)->create(['paid_until' => $now->copy()->subDay(), 'expired_at' => $now])->state($now))->toBe('expired')
        ->and(CustomerService::factory()->for($static)->create(['paid_until' => $now->copy()->addDay(), 'ended_at' => $now])->state($now))->toBe('ended');
});

it('finds the static ip service of a customer and ignores ended ones', function () {
    $account = Account::factory()->create();
    $static = Item::factory()->staticIp()->create();
    CustomerService::factory()->for($account)->for($static)->create(['ended_at' => now()]);
    CustomerService::factory()->for($account)->for(Item::factory()->service())->create();
    $current = CustomerService::factory()->for($account)->for($static)->create();

    expect($account->staticIpService?->id)->toBe($current->id)
        ->and($account->services()->count())->toBe(3)
        ->and($current->isStaticIp())->toBeTrue();
});

it('links renewals and counts an item used by a service', function () {
    $service = CustomerService::factory()->create();
    CustomerServiceRenewal::factory()->for($service, 'service')->create(['months' => 1, 'created_at' => now()->subDay()]);
    $latest = CustomerServiceRenewal::factory()->for($service, 'service')->create(['months' => 3]);
    CustomerServiceRenewal::factory()->for($service, 'service')->create(['months' => 6, 'cancelled_at' => now()]);

    expect($service->latestRenewal?->id)->toBe($latest->id)
        ->and($service->item->isUsed())->toBeTrue();
});

it('grants manage customer services to roles that recharge', function () {
    $desk = Role::create(['name' => 'Front desk']);
    $desk->givePermissionTo(['view-customers', 'recharge-customers']);
    $tech = Role::create(['name' => 'Field']);
    $tech->givePermissionTo(['view-customers']);

    (require database_path('migrations/2026_11_14_000200_create_customer_services.php'))->grant();

    expect($desk->fresh()->permissions->pluck('name')->all())->toContain('manage-customer-services')
        ->and($tech->fresh()->permissions->pluck('name')->all())->toBe(['view-customers'])
        ->and(Permission::ManageCustomerServices->implies())->toBe(Permission::ViewCustomers);
});
