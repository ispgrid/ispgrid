<?php

use App\Actions\Radius\ResetDevices;
use App\Actions\Radius\UnlockAccount;
use Illuminate\Support\Facades\DB;

it('forgets the bound devices of one customer and returns them', function () {
    DB::table('account_macs')->insert([
        ['username' => 'alice', 'mac_norm' => 'BC2411003020'],
        ['username' => 'alice', 'mac_norm' => 'AA0000000001'],
        ['username' => 'bob', 'mac_norm' => 'BB0000000002'],
    ]);

    expect(app(ResetDevices::class)->handle('alice'))->toBe(['AA0000000001', 'BC2411003020'])
        ->and(DB::table('account_macs')->pluck('username')->all())->toBe(['bob']);
});

it('clears the failed-login counters of one customer', function () {
    DB::table('reject_counters')->insert([
        ['username' => 'alice', 'mac' => '', 'counter' => 30, 'last_update' => now()],
        ['username' => 'alice', 'mac' => 'BC2411003020', 'counter' => 4, 'last_update' => now()],
        ['username' => 'bob', 'mac' => '', 'counter' => 2, 'last_update' => now()],
    ]);

    expect(app(UnlockAccount::class)->handle('alice'))->toBe(2)
        ->and(DB::table('reject_counters')->pluck('username')->all())->toBe(['bob']);
});
