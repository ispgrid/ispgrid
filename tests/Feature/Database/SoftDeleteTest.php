<?php

use App\Models\Account;
use App\Models\AddressList;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;

it('keeps the row when a record is deleted', function (string $model) {
    $record = $model::factory()->create();

    $record->delete();

    $this->assertSoftDeleted($record);
})->with([
    'account' => Account::class,
    'plan' => Plan::class,
    'pool' => Pool::class,
    'IP range' => IpRange::class,
    'IP' => Ip::class,
    'address list' => AddressList::class,
    'connection type' => ConnectionType::class,
    'router' => Nas::class,
    'staff user' => User::class,
]);
