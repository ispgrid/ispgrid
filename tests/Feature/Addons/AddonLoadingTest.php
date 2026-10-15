<?php

use App\Providers\AddonServiceProvider;
use App\Support\Addons\AddonManifest;
use Tests\Fixtures\FixtureAddonServiceProvider;

beforeEach(fn () => FixtureAddonServiceProvider::$registered = false);

function manifestWith(array $core): AddonManifest
{
    $path = tempnam(sys_get_temp_dir(), 'installed');
    file_put_contents($path, json_encode(['packages' => [[
        'name' => 'acme/fixture',
        'version' => '0.1.0',
        'extra' => ['ispgrid' => ['name' => 'Fixture', 'core' => $core, 'providers' => [FixtureAddonServiceProvider::class]]],
    ]]]));

    return new AddonManifest($path, 'v0.1.0');
}

it('registers the providers of a compatible add-on', function () {
    (new AddonServiceProvider(app()))->registerAddons(manifestWith(['0.1']));

    expect(FixtureAddonServiceProvider::$registered)->toBeTrue();
});

it('does not load an add-on built for another core series', function () {
    (new AddonServiceProvider(app()))->registerAddons(manifestWith(['0.2']));

    expect(FixtureAddonServiceProvider::$registered)->toBeFalse();
});

it('runs the core tests without add-ons', function () {
    expect(config('ispgrid.addons'))->toBeFalse();
});
