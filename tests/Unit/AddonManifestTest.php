<?php

use App\Support\Addons\AddonManifest;

function installedJson(array $packages): string
{
    $path = tempnam(sys_get_temp_dir(), 'installed');
    file_put_contents($path, json_encode(['packages' => $packages]));

    return $path;
}

function addonPackage(string $name, array $core, array $providers = []): array
{
    return [
        'name' => $name,
        'version' => '0.1.0',
        'extra' => ['ispgrid' => ['name' => 'Fixture '.$name, 'core' => $core, 'providers' => $providers]],
    ];
}

it('finds the add-ons among the installed packages', function () {
    $manifest = new AddonManifest(installedJson([
        ['name' => 'laravel/framework', 'version' => '13.0.0'],
        addonPackage('acme/one', ['0.1'], ['Acme\\One\\Provider']),
    ]), 'v0.1.0');

    expect($manifest->all())->toHaveCount(1)
        ->and($manifest->all()[0]->package)->toBe('acme/one')
        ->and($manifest->all()[0]->name)->toBe('Fixture acme/one')
        ->and($manifest->all()[0]->version)->toBe('0.1.0')
        ->and($manifest->all()[0]->providers)->toBe(['Acme\\One\\Provider'])
        ->and($manifest->all()[0]->compatible)->toBeTrue();
});

it('marks an add-on for another core series as not compatible', function (string $core) {
    $manifest = new AddonManifest(installedJson([addonPackage('acme/old', ['0.2'])]), $core);

    expect($manifest->all()[0]->compatible)->toBeFalse()
        ->and($manifest->compatible())->toBe([]);
})->with(['release' => 'v0.1.0', 'rc' => 'v0.1.0-rc6', 'no v' => '0.1.3']);

it('treats every add-on as compatible on a development checkout', function () {
    $manifest = new AddonManifest(installedJson([addonPackage('acme/any', ['9.9'])]), 'dev');

    expect($manifest->all()[0]->compatible)->toBeTrue();
});

it('reads the series of a version', function (string $version, ?string $series) {
    expect(AddonManifest::series($version))->toBe($series);
})->with([
    ['v0.1.0', '0.1'],
    ['v0.1.0-rc6', '0.1'],
    ['1.12.3', '1.12'],
    ['dev', null],
    ['master', null],
]);

it('has no add-ons without an installed.json', function () {
    expect((new AddonManifest(sys_get_temp_dir().'/missing-installed.json', 'v0.1.0'))->all())->toBe([]);
});
