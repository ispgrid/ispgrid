<?php

use App\Enums\LoginResult;
use App\Models\Nas;
use App\Models\Radacct;
use App\Models\Radpostauth;
use Illuminate\Support\Carbon;

it('sums what a customer used in sessions that started in the period', function () {
    Radacct::factory()->create(['username' => 'alice', 'acctstarttime' => '2026-10-01 09:00:00', 'acctinputoctets' => 100, 'acctoutputoctets' => 900, 'acctsessiontime' => 60]);
    Radacct::factory()->closed()->create(['username' => 'alice', 'acctstarttime' => '2026-10-05 09:00:00', 'acctinputoctets' => 1000, 'acctoutputoctets' => 4000, 'acctsessiontime' => 600]);
    Radacct::factory()->create(['username' => 'bob', 'acctstarttime' => '2026-10-05 09:00:00', 'acctinputoctets' => 7, 'acctoutputoctets' => 7, 'acctsessiontime' => 7]);

    expect(Radacct::usage('alice', Carbon::parse('2026-10-02 00:00:00')))->toBe(['bytes' => 5000, 'seconds' => 600])
        ->and(Radacct::usage('alice', null))->toBe(['bytes' => 6000, 'seconds' => 660])
        ->and(Radacct::usage('nobody', null))->toBe(['bytes' => 0, 'seconds' => 0]);
});

it('finds the sessions that are still open', function () {
    $open = Radacct::factory()->create();
    Radacct::factory()->closed()->create();

    expect(Radacct::query()->open()->pluck('radacctid')->all())->toBe([$open->radacctid]);
});

it('shows a session the way the session tables need it', function () {
    $this->travelTo(Carbon::parse('2026-10-10 12:00:00'));
    $session = Radacct::factory()->create([
        'username' => 'alice', 'nasipaddress' => '10.0.0.1', 'framedipaddress' => '10.10.10.5', 'callingstationid' => 'BC:24:11:00:30:20',
        'acctstarttime' => '2026-10-10 10:55:00', 'acctinputoctets' => 1536, 'acctoutputoctets' => 1_073_741_824,
    ]);

    expect($session->forTable(['10.0.0.1' => 'core'], 'd/m/Y H:i'))->toBe([
        'id' => $session->radacctid,
        'username' => 'alice',
        'router' => 'core',
        'ip' => '10.10.10.5',
        'mac' => 'BC:24:11:00:30:20',
        'started' => '10/10/2026 10:55',
        'duration' => '1 h 05 min',
        'download' => '1.00 GB',
        'upload' => '1.50 KB',
    ]);
});

it('reads the result of a login attempt from its reply', function (string $reply, LoginResult $result) {
    expect(Radpostauth::factory()->create(['reply' => $reply])->result())->toBe($result);
})->with([
    'accepted' => ['Access-Accept', LoginResult::Accepted],
    'rejected' => ['Access-Reject', LoginResult::Rejected],
    'dropped, no answer sent' => ['0', LoginResult::Dropped],
]);

it('shows a login attempt the way the login tables need it', function () {
    $login = Radpostauth::factory()->rejected('Account is suspended')->create([
        'username' => 'alice', 'authdate' => '2026-10-10 09:15:00', 'nasipaddress' => '10.0.0.9', 'mac' => 'BC:24:11:00:30:20',
    ]);

    expect($login->forTable([], 'd/m/Y H:i'))->toBe([
        'id' => $login->id,
        'username' => 'alice',
        'when' => '10/10/2026 09:15',
        'result' => 'Rejected',
        'color' => 'red',
        'reason' => 'Account is suspended',
        'router' => '10.0.0.9',
        'mac' => 'BC:24:11:00:30:20',
    ]);
});

it('names every router by its IP, deleted routers included', function () {
    Nas::factory()->create(['nasname' => '10.0.0.1', 'shortname' => 'core']);
    Nas::factory()->create(['nasname' => '10.0.0.2', 'shortname' => null]);
    Nas::factory()->trashed()->create(['nasname' => '10.0.0.3', 'shortname' => 'old']);

    expect(Nas::names())->toEqual(['10.0.0.1' => 'core', '10.0.0.2' => '10.0.0.2', '10.0.0.3' => 'old']);
});
