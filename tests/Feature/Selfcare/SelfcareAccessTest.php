<?php

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Support\Selfcare;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusSettingSeeder;
use Livewire\Livewire;

it('lets PPPoE and IPoE customers with active, inactive or suspended status in', function (ServiceType $type, AccountStatus $status) {
    $account = Account::factory()->create(['service_type' => $type, 'status_id' => $status]);

    expect(Selfcare::eligible($account))->toBeTrue();
})->with([ServiceType::Ppp, ServiceType::Ipoe])->with([AccountStatus::Active, AccountStatus::Inactive, AccountStatus::Suspended]);

it('keeps hotspot, leased line, terminated and deleted customers out', function (Closure $make) {
    expect(Selfcare::eligible($make()))->toBeFalse();
})->with([
    'hotspot' => fn () => Account::factory()->hotspot()->create(),
    'leased line' => fn () => Account::factory()->leasedLine()->create(),
    'terminated' => fn () => Account::factory()->terminated()->create(),
    'deleted' => fn () => tap(Account::factory()->create())->delete(),
]);

it('is closed until the switch is on', function () {
    expect(Selfcare::isOpen())->toBeFalse()
        ->and(Selfcare::canChangePppoePassword())->toBeFalse();

    AppSetting::put('selfcare.enabled', '1');
    AppSetting::put('selfcare.pppoe_password_change', '1');

    expect(Selfcare::isOpen())->toBeTrue()
        ->and(Selfcare::canChangePppoePassword())->toBeTrue();
});

it('lets PPPoE password changes only while selfcare itself is open', function () {
    AppSetting::put('selfcare.pppoe_password_change', '1');

    expect(Selfcare::canChangePppoePassword())->toBeFalse();
});

it('gives the new box to roles that can edit customers', function () {
    $editor = Role::create(['name' => 'Editor']);
    $editor->givePermissionTo(['view-customers', 'edit-customers']);
    $viewer = Role::create(['name' => 'Viewer']);
    $viewer->givePermissionTo(['view-customers']);

    (require database_path('migrations/2026_11_05_000000_add_selfcare_to_accounts.php'))->grant();

    expect($editor->fresh()->permissions->pluck('name')->all())->toContain('set-selfcare-passwords')
        ->and($viewer->fresh()->permissions->pluck('name')->all())->toBe(['view-customers']);
});

it('never fills the selfcare columns from mass assignment', function () {
    $account = Account::create([
        'username' => 'massfill', 'password' => 'pw12345', 'fullname' => 'Mass Fill', 'service_type' => ServiceType::Ppp, 'plan_id' => Plan::factory()->create()->id,
        'selfcare_password' => 'x', 'selfcare_must_change' => true,
    ])->refresh();

    expect($account->selfcare_password)->toBeNull()
        ->and($account->selfcare_must_change)->toBeFalse()
        ->and($account->toArray())->not->toHaveKey('selfcare_password');
});

it('saves the two selfcare switches on the App settings page', function () {
    $this->seed(AppSettingSeeder::class);
    $this->seed(RadiusSettingSeeder::class);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::system.app-settings')
        ->assertSet('selfcare_enabled', false)
        ->set('selfcare_enabled', true)
        ->set('selfcare_pppoe_password_change', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::valueOf('selfcare.enabled'))->toBe('1')
        ->and(AppSetting::valueOf('selfcare.pppoe_password_change'))->toBe('1');
});
