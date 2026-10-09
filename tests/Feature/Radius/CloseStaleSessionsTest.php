<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * An open radacct session. Times are computed by the database (NOW()), like FreeRADIUS's own rows,
 * so the app timezone can't skew them.
 */
function openRadacctSession(string $id, ?int $updatedMinutesAgo, int $startedMinutesAgo = 120): void
{
    DB::table('radacct')->insert([
        'acctsessionid' => $id,
        'acctuniqueid' => $id,
        'username' => 'cust1',
        'nasipaddress' => '192.0.2.1',
        'acctstarttime' => DB::raw("NOW() - INTERVAL {$startedMinutesAgo} MINUTE"),
        'acctupdatetime' => $updatedMinutesAgo === null ? null : DB::raw("NOW() - INTERVAL {$updatedMinutesAgo} MINUTE"),
    ]);
}

function radacctRow(string $id): object
{
    return DB::table('radacct')->where('acctuniqueid', $id)->first();
}

it('closes a session with no accounting update for 15 minutes at its last update', function () {
    openRadacctSession('stale', updatedMinutesAgo: 20);

    $this->artisan('radius:close-stale')
        ->expectsOutput('Closed 1 stale session.')
        ->assertSuccessful();

    $closed = radacctRow('stale');
    expect($closed->acctstoptime)->toBe($closed->acctupdatetime)
        ->and($closed->acctterminatecause)->toBe('Stale-Session');
});

it('uses the start time when a session never sent an update', function () {
    openRadacctSession('never-updated', updatedMinutesAgo: null, startedMinutesAgo: 30);

    $this->artisan('radius:close-stale')->assertSuccessful();

    $closed = radacctRow('never-updated');
    expect($closed->acctstoptime)->toBe($closed->acctstarttime);
});

it('leaves recently updated and already closed sessions alone', function () {
    openRadacctSession('fresh', updatedMinutesAgo: 5);
    openRadacctSession('closed', updatedMinutesAgo: 60);
    DB::table('radacct')->where('acctuniqueid', 'closed')->update([
        'acctstoptime' => DB::raw('NOW() - INTERVAL 59 MINUTE'),
        'acctterminatecause' => 'User-Request',
    ]);

    $this->artisan('radius:close-stale')->expectsOutput('Closed 0 stale sessions.')->assertSuccessful();

    expect(radacctRow('fresh')->acctstoptime)->toBeNull()
        ->and(radacctRow('closed')->acctterminatecause)->toBe('User-Request');
});

it('takes the silence limit in minutes', function () {
    openRadacctSession('twenty', updatedMinutesAgo: 20);

    $this->artisan('radius:close-stale', ['--minutes' => 30])->assertSuccessful();
    expect(radacctRow('twenty')->acctstoptime)->toBeNull();

    $this->artisan('radius:close-stale', ['--minutes' => 10])->assertSuccessful();
    expect(radacctRow('twenty')->acctstoptime)->not->toBeNull();
});

it('refuses a limit below one minute, which would close every session', function () {
    openRadacctSession('online', updatedMinutesAgo: 0);

    $this->artisan('radius:close-stale', ['--minutes' => 0])
        ->expectsOutput('--minutes must be at least 1.')
        ->assertFailed();

    expect(radacctRow('online')->acctstoptime)->toBeNull();
});

it('runs every five minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'radius:close-stale'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});
