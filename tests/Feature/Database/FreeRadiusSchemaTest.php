<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The SQL below is copied from the isp virtual server, so these tests fail if the schema drifts from what
 * FreeRADIUS expects.
 */
function countReject(string $username, string $mac): void
{
    DB::insert(
        'INSERT INTO reject_counters (username, mac, counter, last_update) VALUES (?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE counter = IF(last_update >= NOW() - INTERVAL 10 MINUTE, counter + 1, 1), last_update = NOW()',
        [$username, $mac],
    );
}

function bindMac(string $username, string $mac): void
{
    DB::insert(
        'INSERT INTO account_macs (username, mac_norm, learned_at, last_seen) VALUES (?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE last_seen = NOW()',
        [$username, $mac],
    );
}

it('counts rejects separately per user and MAC', function () {
    countReject('alice', 'AABBCCDDEEFF');
    countReject('alice', 'AABBCCDDEEFF');
    countReject('alice', '');

    expect(DB::table('reject_counters')->where('mac', 'AABBCCDDEEFF')->value('counter'))->toBe(2)
        ->and(DB::table('reject_counters')->where('mac', '')->value('counter'))->toBe(1);
});

it('refreshes an existing MAC binding instead of adding a second row', function () {
    bindMac('alice', 'AABBCCDDEEFF');
    bindMac('alice', 'AABBCCDDEEFF');

    expect(DB::table('account_macs')->count())->toBe(1);
});

it('refuses to bind one MAC to two accounts', function () {
    bindMac('alice', 'AABBCCDDEEFF');

    DB::table('account_macs')->insert(['username' => 'bob', 'mac_norm' => 'AABBCCDDEEFF']);
})->throws(UniqueConstraintViolationException::class);

it('stores the login log row isp writes, with reason and MAC', function () {
    DB::insert(
        "INSERT INTO radpostauth (username, pass, reply, reply_msg, authdate, nasipaddress, mac)
         VALUES (?, '', 'Access-Reject', ?, NOW(), ?, ?)",
        ['alice', 'Account is inactive', '10.0.0.1', 'AA:BB:CC:DD:EE:FF'],
    );

    expect(DB::table('radpostauth')->first())
        ->reply_msg->toBe('Account is inactive')
        ->mac->toBe('AA:BB:CC:DD:EE:FF');
});
