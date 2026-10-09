<?php

use App\Support\Ipv4Cidr;

it('clears the host bits of a network', function () {
    expect((string) Ipv4Cidr::parse('10.0.0.9/29'))->toBe('10.0.0.8/29');
});

it('refuses what is not an IPv4 network with a prefix', function (string $value) {
    expect(Ipv4Cidr::parse($value))->toBeNull();
})->with(['10.0.0.0', '10.0.0.0/33', '2001:db8::/64', 'lan/24', '10.0.0.256/24']);

it('lists the addresses a customer can get', function (string $network, array $hosts) {
    expect(Ipv4Cidr::parse($network)->hosts())->toBe($hosts);
})->with([
    '/30 without network and broadcast' => ['10.0.0.0/30', ['10.0.0.1', '10.0.0.2']],
    '/31 point to point' => ['10.0.0.0/31', ['10.0.0.0', '10.0.0.1']],
    '/32 single address' => ['10.0.0.7/32', ['10.0.0.7']],
]);

it('counts 254 hosts in a /24 and gives its mask', function () {
    $cidr = Ipv4Cidr::parse('192.168.5.0/24');

    expect($cidr->hosts())->toHaveCount(254)
        ->and($cidr->size())->toBe(256)
        ->and($cidr->netmask())->toBe('255.255.255.0');
});

it('knows which addresses are hosts of the network', function () {
    $cidr = Ipv4Cidr::parse('10.0.0.0/29');

    expect($cidr->isHost('10.0.0.1'))->toBeTrue()
        ->and($cidr->isHost('10.0.0.0'))->toBeFalse()
        ->and($cidr->isHost('10.0.0.7'))->toBeFalse()
        ->and($cidr->isHost('10.0.0.9'))->toBeFalse()
        ->and($cidr->contains('10.0.0.7'))->toBeTrue();
});

it('finds overlapping networks', function () {
    $wide = Ipv4Cidr::parse('10.0.0.0/23');

    expect($wide->overlaps(Ipv4Cidr::parse('10.0.1.128/25')))->toBeTrue()
        ->and(Ipv4Cidr::parse('10.0.0.0/24')->overlaps(Ipv4Cidr::parse('10.0.1.0/24')))->toBeFalse();
});
