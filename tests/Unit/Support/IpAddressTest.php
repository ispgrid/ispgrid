<?php

use App\Support\IpAddress;

it('keeps an IPv4 address as typed, without spaces', function () {
    expect(IpAddress::normalize(' 10.0.0.1 '))->toBe('10.0.0.1');
});

it('writes an IPv6 address in its short lower-case form', function () {
    expect(IpAddress::normalize('2001:DB8:0:0:0:0:0:1'))->toBe('2001:db8::1');
});

it('refuses what is not an IP address', function (string $value) {
    expect(IpAddress::normalize($value))->toBeNull();
})->with(['router.lan', '10.0.0.256', '010.0.0.1', '10.0.0', '']);
