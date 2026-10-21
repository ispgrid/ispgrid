<?php

use App\Contracts\SessionKeeper;
use App\Models\Account;
use App\Models\AppSetting;
use App\Support\SessionKeepers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

/**
 * A session the way FreeRADIUS writes it: times from the database clock. $stoppedAgo null = still open.
 */
function sessionRow(string $username, ?string $stoppedAgo, ?string $startedAgo = null): void
{
    $startedAgo ??= $stoppedAgo ?? '1 HOUR';

    DB::table('radacct')->insert([
        'acctsessionid' => Str::random(8),
        'acctuniqueid' => Str::random(32),
        'username' => $username,
        'nasipaddress' => '10.0.0.1',
        'acctstarttime' => DB::raw("NOW() - INTERVAL {$startedAgo}"),
        'acctstoptime' => $stoppedAgo === null ? null : DB::raw("NOW() - INTERVAL {$stoppedAgo}"),
    ]);
}

/**
 * @return array<int, string>
 */
function sessionUsernames(): array
{
    return DB::table('radacct')->orderBy('username')->pluck('username')->all();
}

it('removes login log rows older than 90 days by default and keeps newer ones', function () {
    loginLogRow('old', '91 DAY');
    loginLogRow('recent', '89 DAY');

    $this->artisan('radius:prune')
        ->expectsOutput('Login log: 1 rows removed. Reject counters: 0 rows removed. Sessions: 0 rows removed. Usage days: 0 rows removed.')
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
        ->expectsOutput('Login log: 0 rows removed. Reject counters: 1 rows removed. Sessions: 0 rows removed. Usage days: 0 rows removed.')
        ->assertSuccessful();

    expect(DB::table('reject_counters')->pluck('username')->all())->toBe(['locked']);
});

it('reports zero when there is nothing to remove', function () {
    $this->artisan('radius:prune')
        ->expectsOutput('Login log: 0 rows removed. Reject counters: 0 rows removed. Sessions: 0 rows removed. Usage days: 0 rows removed.')
        ->assertSuccessful();
});

it('deletes the login log in chunks so FreeRADIUS inserts never wait long', function () {
    foreach (['a', 'b', 'c', 'd', 'e'] as $username) {
        loginLogRow($username, '100 DAY');
    }
    DB::enableQueryLog();

    $this->artisan('radius:prune', ['--chunk' => 2])
        ->expectsOutput('Login log: 5 rows removed. Reject counters: 0 rows removed. Sessions: 0 rows removed. Usage days: 0 rows removed.')
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

    Log::shouldHaveReceived('info')->with('Login log: 1 rows removed. Reject counters: 0 rows removed. Sessions: 0 rows removed. Usage days: 0 rows removed.')->once();
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

it('walks the login log along its date index, oldest first', function () {
    loginLogRow('old', '100 DAY');
    DB::enableQueryLog();

    $this->artisan('radius:prune')->assertSuccessful();

    $delete = collect(DB::getQueryLog())
        ->first(fn (array $query): bool => str_starts_with($query['query'], 'delete from `radpostauth`'));
    expect($delete['query'])->toEndWith('order by `authdate` asc, `id` asc limit 5000');
});

it('removes closed sessions older than 365 days by default and keeps newer ones', function () {
    sessionRow('old', '366 DAY');
    sessionRow('recent', '364 DAY');

    $this->artisan('radius:prune')
        ->expectsOutput('Login log: 0 rows removed. Reject counters: 0 rows removed. Sessions: 1 rows removed. Usage days: 0 rows removed.')
        ->assertSuccessful();

    expect(sessionUsernames())->toBe(['recent']);
});

it('never removes an open session, however old', function () {
    sessionRow('open', null, '900 DAY');

    $this->artisan('radius:prune')->assertSuccessful();

    expect(sessionUsernames())->toBe(['open']);
});

it('removes old sessions since the last recharge too: limits read the usage database', function () {
    Account::factory()->create(['username' => 'yearly', 'usage_since' => now()->subDays(400)]);
    sessionRow('yearly', '389 DAY', '390 DAY');

    $this->artisan('radius:prune')->assertSuccessful();

    expect(sessionUsernames())->toBe([]);
});

it('keeps usage per day for 400 days, or the session history when longer', function () {
    $table = config('ispgrid.usage_database').'.usage_daily';
    foreach ([399, 401, 500] as $age) {
        DB::table($table)->insert(['username' => "u{$age}", 'day' => DB::raw("CURDATE() - INTERVAL {$age} DAY"), 'bytes_out' => 1]);
    }

    $this->artisan('radius:prune')->expectsOutputToContain('Usage days: 2 rows removed.')->assertSuccessful();
    expect(DB::table($table)->pluck('username')->all())->toBe(['u399']);

    AppSetting::put('session_history_days', '450');
    DB::table($table)->insert(['username' => 'u420', 'day' => DB::raw('CURDATE() - INTERVAL 420 DAY'), 'bytes_out' => 1]);
    $this->artisan('radius:prune')->assertSuccessful();
    expect(DB::table($table)->orderBy('username')->pluck('username')->all())->toBe(['u399', 'u420']);
});

it('removes old sessions of a deleted customer', function () {
    Account::factory()->create(['username' => 'gone', 'usage_since' => now()->subDays(400)])->delete();
    sessionRow('gone', '389 DAY', '390 DAY');

    $this->artisan('radius:prune')->assertSuccessful();

    expect(sessionUsernames())->toBe([]);
});

it('keeps sessions for the days set in App settings', function () {
    AppSetting::put('session_history_days', '40');
    sessionRow('fifty', '50 DAY');
    sessionRow('thirty', '30 DAY');

    $this->artisan('radius:prune')->assertSuccessful();

    expect(sessionUsernames())->toBe(['thirty']);
});

it('falls back to 365 days for a session setting it cannot use', function (string $value) {
    AppSetting::put('session_history_days', $value);
    sessionRow('old', '366 DAY');
    sessionRow('recent', '364 DAY');

    $this->artisan('radius:prune')->assertSuccessful();

    expect(sessionUsernames())->toBe(['recent']);
})->with([
    'under a month' => ['30'],
    'above ten years' => ['3651'],
    'not a number' => ['abc'],
]);

it('removes sessions in batches', function () {
    foreach (range(1, 5) as $n) {
        sessionRow("old{$n}", '400 DAY');
    }

    $this->artisan('radius:prune', ['--chunk' => 2])
        ->expectsOutput('Login log: 0 rows removed. Reject counters: 0 rows removed. Sessions: 5 rows removed. Usage days: 0 rows removed.')
        ->assertSuccessful();
});

class KeepsListedSessions implements SessionKeeper
{
    /** @var list<string> */
    public static array $kept = [];

    public function kept(array $acctUniqueIds): array
    {
        return array_values(array_intersect($acctUniqueIds, self::$kept));
    }
}

class ReturnsStrangers implements SessionKeeper
{
    public function kept(array $acctUniqueIds): array
    {
        return ['not-asked-1', ...array_reverse($acctUniqueIds), 'not-asked-2'];
    }
}

class BrokenKeeper implements SessionKeeper
{
    public function kept(array $acctUniqueIds): array
    {
        throw new RuntimeException('archive database is down');
    }
}

it('removes only the old sessions every keeper already has', function () {
    sessionRow('saved', '400 DAY');
    sessionRow('unsaved', '400 DAY');
    KeepsListedSessions::$kept = [DB::table('radacct')->where('username', 'saved')->value('acctuniqueid')];
    app(SessionKeepers::class)->register(KeepsListedSessions::class);

    $this->artisan('radius:prune')
        ->expectsOutput('Login log: 0 rows removed. Reject counters: 0 rows removed. Sessions: 1 rows removed. Usage days: 0 rows removed.')
        ->assertSuccessful();

    expect(sessionUsernames())->toBe(['unsaved']);
});

it('ignores ids a keeper returns that it was not asked about', function () {
    sessionRow('old', '400 DAY');
    sessionRow('recent', '10 DAY');
    app(SessionKeepers::class)->register(ReturnsStrangers::class);

    $this->artisan('radius:prune')->assertSuccessful();

    expect(sessionUsernames())->toBe(['recent']);
});

it('stops removing sessions when a keeper fails, and says so', function () {
    Log::spy();
    sessionRow('old', '400 DAY');
    app(SessionKeepers::class)->register(BrokenKeeper::class);

    $this->artisan('radius:prune')
        ->expectsOutputToContain('Session cleanup stopped: archive database is down')
        ->assertFailed();

    expect(sessionUsernames())->toBe(['old']);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'archive database is down'));
});
