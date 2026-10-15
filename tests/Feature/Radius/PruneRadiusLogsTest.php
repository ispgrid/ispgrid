<?php

use App\Models\AppSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A login log row the way FreeRADIUS writes it: authdate comes from the database clock (NOW()),
 * so the app timezone can't skew it.
 */
function loginLogRow(string $username, string $age): void
{
    DB::table('radpostauth')->insert([
        'username' => $username,
        'reply' => 'Access-Accept',
        'authdate' => DB::raw("NOW() - INTERVAL {$age}"),
    ]);
}

function rejectCounterRow(string $username, string $age): void
{
    DB::table('reject_counters')->insert([
        'username' => $username,
        'mac' => '',
        'counter' => 3,
        'last_update' => DB::raw("NOW() - INTERVAL {$age}"),
    ]);
}

/**
 * @return array<int, string>
 */
function loginLogUsernames(): array
{
    return DB::table('radpostauth')->orderBy('username')->pluck('username')->all();
}

it('removes login log rows older than 90 days by default and keeps newer ones', function () {
    loginLogRow('old', '91 DAY');
    loginLogRow('recent', '89 DAY');

    $this->artisan('radius:prune')
        ->expectsOutput('Login log: 1 rows removed. Reject counters: 0 rows removed.')
        ->assertSuccessful();

    expect(loginLogUsernames())->toBe(['recent']);
});

it('keeps the login log for the days set in App settings', function () {
    AppSetting::put('login_log_days', '30');
    loginLogRow('forty', '40 DAY');
    loginLogRow('twenty', '20 DAY');

    $this->artisan('radius:prune')->assertSuccessful();

    expect(loginLogUsernames())->toBe(['twenty']);
});

it('falls back to 90 days for a value it cannot use', function (?string $value) {
    if ($value !== null) {
        AppSetting::put('login_log_days', $value);
    }
    loginLogRow('old', '91 DAY');
    loginLogRow('recent', '89 DAY');

    $this->artisan('radius:prune')->assertSuccessful();

    expect(loginLogUsernames())->toBe(['recent']);
})->with([
    'no setting row' => [null],
    'below a week' => ['3'],
    'above ten years' => ['3651'],
    'not a number' => ['abc'],
    'a fraction' => ['30.5'],
    'empty' => [''],
]);

it('accepts a plain number with spaces around it', function () {
    AppSetting::put('login_log_days', ' 30 ');
    loginLogRow('forty', '40 DAY');

    $this->artisan('radius:prune')->assertSuccessful();

    expect(loginLogUsernames())->toBe([]);
});

it('judges age by the database clock, whatever the app timezone', function () {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('Pacific/Kiritimati');
    config(['app.timezone' => 'Pacific/Kiritimati']);

    try {
        loginLogRow('almost', '89 DAY - INTERVAL 20 HOUR');

        $this->artisan('radius:prune')->assertSuccessful();
    } finally {
        date_default_timezone_set($timezone);
    }

    expect(loginLogUsernames())->toBe(['almost']);
});

it('removes reject counters untouched for a day and keeps recent ones', function () {
    rejectCounterRow('stale', '25 HOUR');
    rejectCounterRow('locked', '23 HOUR');

    $this->artisan('radius:prune')
        ->expectsOutput('Login log: 0 rows removed. Reject counters: 1 rows removed.')
        ->assertSuccessful();

    expect(DB::table('reject_counters')->pluck('username')->all())->toBe(['locked']);
});

it('reports zero when there is nothing to remove', function () {
    $this->artisan('radius:prune')
        ->expectsOutput('Login log: 0 rows removed. Reject counters: 0 rows removed.')
        ->assertSuccessful();
});

it('deletes the login log in chunks so FreeRADIUS inserts never wait long', function () {
    foreach (['a', 'b', 'c', 'd', 'e'] as $username) {
        loginLogRow($username, '100 DAY');
    }
    DB::enableQueryLog();

    $this->artisan('radius:prune', ['--chunk' => 2])
        ->expectsOutput('Login log: 5 rows removed. Reject counters: 0 rows removed.')
        ->assertSuccessful();

    $deletes = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_starts_with($query['query'], 'delete from `radpostauth`'));
    expect($deletes)->toHaveCount(3)
        ->and(loginLogUsernames())->toBe([]);
});

it('refuses a chunk below one row', function () {
    loginLogRow('old', '100 DAY');

    $this->artisan('radius:prune', ['--chunk' => 0])
        ->expectsOutput('--chunk must be at least 1.')
        ->assertFailed();

    expect(loginLogUsernames())->toBe(['old']);
});

it('writes the counts to the log', function () {
    Log::spy();
    loginLogRow('old', '100 DAY');

    $this->artisan('radius:prune')->assertSuccessful();

    Log::shouldHaveReceived('info')->with('Login log: 1 rows removed. Reject counters: 0 rows removed.')->once();
});

it('runs every night at 03:30 without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'radius:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('indexes the login log by date for the cleanup and the Login log page', function () {
    $index = collect(Schema::getIndexes('radpostauth'))->firstWhere('name', 'idx_radpostauth_date');

    expect($index)->not->toBeNull()
        ->and($index['columns'])->toBe(['authdate']);
});
