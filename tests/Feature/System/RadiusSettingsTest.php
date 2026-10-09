<?php

use App\Models\Pool;
use App\Models\RadiusSetting;
use App\Models\User;
use Database\Seeders\RadiusSettingSeeder;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RadiusSettingSeeder::class);
    $this->actingAs(User::factory()->create());
    config(['radius.restart_command' => 'sudo systemctl restart freeradius']);
    Process::fake();
});

it('shows the current settings', function () {
    Livewire::test('pages::system.radius-settings')
        ->assertSet('expire_type', '1')
        ->assertSet('disabled_users_pool', 'disabled_pool')
        ->assertSet('reject_requests', '30')
        ->assertSet('count_and_reject', true);
});

it('saves the settings and restarts FreeRADIUS', function () {
    Livewire::test('pages::system.radius-settings')
        ->set('reject_requests', '10')
        ->set('count_and_reject', false)
        ->set('expire_type', '0')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', variant: 'success', message: 'FreeRADIUS settings saved. FreeRADIUS restarted.');

    $settings = RadiusSetting::current();

    expect($settings->reject_requests)->toBe(10)
        ->and($settings->count_and_reject)->toBe(0)
        ->and($settings->expire_type)->toBe(0);
    Process::assertRan('sudo systemctl restart freeradius');
});

it('saves nothing and does not restart when one field is invalid', function () {
    Livewire::test('pages::system.radius-settings')
        ->set('reject_requests', '10')
        ->set('reject_session_timeout', '70000')
        ->call('save')
        ->assertHasErrors(['reject_session_timeout'])
        ->assertSee('Nothing was saved.');

    expect(RadiusSetting::current()->reject_requests)->toBe(30);
    Process::assertNothingRan();
});

it('does not restart FreeRADIUS when nothing changed', function () {
    Livewire::test('pages::system.radius-settings')
        ->call('save')
        ->assertDispatched('toast', message: 'Nothing changed.');

    Process::assertNothingRan();
});

it('refuses a disabled pool name FreeRADIUS cannot read', function (string $name) {
    Livewire::test('pages::system.radius-settings')
        ->set('disabled_users_pool', $name)
        ->call('save')
        ->assertHasErrors(['disabled_users_pool']);
})->with(['pool|x', 'two words', '']);

it('warns when no pool has the disabled pool name', function () {
    Livewire::test('pages::system.radius-settings')->assertSee('No pool is named disabled_pool');

    Pool::factory()->create(['name' => 'disabled_pool']);

    Livewire::test('pages::system.radius-settings')->assertDontSee('No pool is named disabled_pool');
});

it('links the System menu to the FreeRADIUS settings', function () {
    $this->get(route('dashboard'))->assertSee(route('radius-settings.edit'));
});
