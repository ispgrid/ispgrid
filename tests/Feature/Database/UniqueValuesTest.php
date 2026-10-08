<?php

use App\Models\AddressList;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\Pool;
use Illuminate\Database\UniqueConstraintViolationException;

it('rejects a duplicate value', function (Closure $createTwice) {
    $createTwice();
})->with([
    'pool name' => fn () => Pool::factory()->count(2)->create(['name' => 'pppoe']),
    'IP address' => fn () => Ip::factory()->count(2)->create(['address' => '203.0.113.25']),
    'IP range network' => fn () => IpRange::factory()->count(2)->create(['network' => '203.0.113.16/28']),
    'address list name' => fn () => AddressList::factory()->count(2)->create(['name' => 'business']),
    'router address' => fn () => Nas::factory()->count(2)->create(['nasname' => '10.0.0.1']),
    'connection type name' => fn () => ConnectionType::factory()->count(2)->create(['name' => 'Home']),
])->throws(UniqueConstraintViolationException::class);
