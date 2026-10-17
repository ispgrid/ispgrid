<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Addons\AddonManifest;
use App\Support\Addons\AddonSettings;
use App\Support\Permissions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionRow;
use Tests\Fixtures\FixtureSettings;

beforeEach(function () {
    $path = tempnam(sys_get_temp_dir(), 'installed');
    file_put_contents($path, json_encode(['packages' => [
        ['name' => 'acme/new', 'version' => '0.1.2', 'description' => 'Keeps new things.', 'extra' => ['ispgrid' => ['name' => 'New add-on', 'core' => ['0.1']]]],
        ['name' => 'acme/old', 'version' => '0.0.9', 'extra' => ['ispgrid' => ['name' => 'Old add-on', 'core' => ['0.0']]]],
    ]]));
    app()->instance(AddonManifest::class, new AddonManifest($path, 'v0.1.0'));

    app(Permissions::class)->register('fixture-settings', fn (): string => 'Fixture settings', fn (): string => __('System'));
    PermissionRow::findOrCreate('fixture-settings', 'web');
    Livewire::component('fixture-settings', FixtureSettings::class);
    app(AddonSettings::class)->register('acme/new', 'fixture-settings', 'fixture-settings');
});

function roleWith(string $name, array $permissions): User
{
    Role::create(['name' => $name, 'guard_name' => 'web'])->givePermissionTo($permissions);

    return User::factory()->role($name)->create();
}

it('lists the installed add-ons with version, description and status', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('addons.index'))
        ->assertOk()
        ->assertSeeInOrder(['New add-on', 'acme/new', '0.1.2', 'Keeps new things.', __('Active'), 'Old add-on', __('Not loaded: built for core :supports, this is :core', ['supports' => '0.0', 'core' => '0.1'])]);
});

it('says when no add-on is installed', function () {
    app()->instance(AddonManifest::class, new AddonManifest(sys_get_temp_dir().'/none.json', 'v0.1.0'));

    $this->actingAs(User::factory()->create())
        ->get(route('addons.index'))
        ->assertSee(__('No add-ons installed.'));
});

it('shows an add-on settings section under its add-on to staff with its permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('addons.index'))
        ->assertSeeInOrder(['New add-on', 'Fixture settings section', 'Old add-on']);
});

it('hides the settings section from staff without its permission', function () {
    $this->actingAs(roleWith('Settings desk', ['manage-app-settings']))
        ->get(route('addons.index'))
        ->assertOk()
        ->assertSee('New add-on')
        ->assertDontSee('Fixture settings section');
});

it('opens for staff who may change only one add-on\'s settings', function () {
    $this->actingAs(roleWith('Add-on desk', ['fixture-settings']))
        ->get(route('addons.index'))
        ->assertOk()
        ->assertSee('Fixture settings section');
});

it('refuses staff with neither App settings nor an add-on settings permission, and hides the menu item', function () {
    $technician = User::factory()->role(Role::TECHNICIAN)->create();

    $this->actingAs($technician)->get(route('addons.index'))->assertForbidden();
    $this->actingAs($technician)->get(route('dashboard'))->assertDontSee(route('addons.index'));
});

it('shows the Add-ons page in the System menu after App settings', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('addons.index'))
        ->assertSeeInOrder([route('app-settings.edit'), route('addons.index'), route('radius-settings.edit')])
        ->assertSee("navGroup('system', true)", false);
});
