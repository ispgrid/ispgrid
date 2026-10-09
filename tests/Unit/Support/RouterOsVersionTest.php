<?php

use App\Support\RouterOsVersion;

it('knows which RouterOS versions send Message-Authenticator', function (string $version, bool $sends) {
    expect(RouterOsVersion::parse($version)->sendsMessageAuthenticator())->toBe($sends);
})->with([
    ['7.23.7', true],
    ['7.15', true],
    ['7.14.3', false],
    ['6.49.18', true],
    ['6.49.22', true],
    ['6.49.17', false],
    ['6.48.6', false],
    ['8.0', true],
    ['5.26', false],
]);

it('refuses what is not a RouterOS release number', function (string $value) {
    expect(RouterOsVersion::parse($value))->toBeNull();
})->with(['7', 'v7.15', '7.15rc1', '', '7.15.1.2']);
