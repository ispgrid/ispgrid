<?php

use App\Models\RadiusAttribute;
use App\Support\RadiusAttributeValue;

function allowlistEntry(string $type): RadiusAttribute
{
    return new RadiusAttribute(['name' => 'Test-Attribute', 'type' => $type, 'allowed_values' => ['yes', 'no']]);
}

it('accepts a value FreeRADIUS can parse', function (string $type, string $value) {
    expect(RadiusAttributeValue::error(allowlistEntry($type), $value))->toBeNull();
})->with([
    ['integer', '300'],
    ['integer', '0'],
    ['ipv4', '255.255.255.0'],
    ['ipv4-prefix', '10.0.0.0/24'],
    ['ipv6-prefix', '2001:db8::/48'],
    ['enum', 'yes'],
    ['text', '192.168.10.0/24 10.0.0.1 1'],
]);

it('refuses a value that would block the login', function (string $type, string $value) {
    expect(RadiusAttributeValue::error(allowlistEntry($type), $value))->toBeString();
})->with([
    ['integer', '5m'],
    ['integer', '-1'],
    ['integer', '4294967296'],
    ['ipv4', '10.0.0'],
    ['ipv4-prefix', '10.0.0.0/33'],
    ['ipv4-prefix', '10.0.0.0'],
    ['ipv6-prefix', '10.0.0.0/24'],
    ['enum', 'maybe'],
    ['text', 'say "hi"'],
    ['text', "line\nbreak"],
]);
