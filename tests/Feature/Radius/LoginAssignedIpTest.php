<?php

use App\Models\Radacct;
use App\Models\Radpostauth;
use Illuminate\Support\Facades\DB;

/**
 * An accept as FreeRADIUS logs it, made $minutesAgo by the database clock (the trigger compares with NOW()).
 *
 * @param  array<string, mixed>  $attributes
 */
function loggedLogin(array $attributes, int $minutesAgo = 0): Radpostauth
{
    $login = Radpostauth::factory()->create($attributes + ['username' => 'asha', 'acctsessionid' => null]);
    DB::table('radpostauth')->where('id', $login->id)->update(['authdate' => DB::raw("NOW() - INTERVAL {$minutesAgo} MINUTE")]);

    return $login->refresh();
}

function sessionStarts(string $sessionId, string $ip): void
{
    Radacct::factory()->create(['username' => 'asha', 'acctsessionid' => $sessionId, 'framedipaddress' => $ip]);
}

it('gives the login the IP of the session with its session id, keeping the login time', function () {
    $login = loggedLogin(['acctsessionid' => '8110003d'], 5);

    sessionStarts('8110003d', '10.20.0.7');

    expect($login->fresh())
        ->framedipaddress->toBe('10.20.0.7')
        ->authdate->toEqual($login->authdate);
});

it('gives each login the IP of its own session', function () {
    $first = loggedLogin(['acctsessionid' => 'aa01'], 1);
    $second = loggedLogin(['acctsessionid' => 'aa02']);

    sessionStarts('aa01', '10.20.0.1');
    sessionStarts('aa02', '10.20.0.2');

    expect($first->fresh()->framedipaddress)->toBe('10.20.0.1')
        ->and($second->fresh()->framedipaddress)->toBe('10.20.0.2');
});

it('matches a login without a session id by its MAC in the last two minutes', function () {
    $login = loggedLogin([]);

    sessionStarts('bb01', '10.20.0.3');

    expect($login->fresh()->framedipaddress)->toBe('10.20.0.3');
});

it('leaves logins alone that the session does not belong to', function (array $attributes, int $minutesAgo) {
    $login = loggedLogin($attributes, $minutesAgo);

    sessionStarts('cc01', '10.20.0.4');

    expect($login->fresh()->framedipaddress)->toBeNull();
})->with([
    'another session id' => [['acctsessionid' => 'cc02'], 0],
    'session id too old' => [['acctsessionid' => 'cc01'], 20],
    'no session id, too old' => [[], 3],
    'no session id, another MAC' => [['mac' => 'BC:24:11:00:30:99'], 0],
    'another NAS' => [['acctsessionid' => 'cc01', 'nasipaddress' => '10.0.0.2'], 0],
    'a refused login' => [['acctsessionid' => 'cc01', 'reply' => 'Access-Reject'], 0],
]);

it('keeps the first IP when the session row is written again', function () {
    $login = loggedLogin(['acctsessionid' => 'dd01']);
    sessionStarts('dd01', '10.20.0.5');

    sessionStarts('dd01', '10.20.0.6');

    expect($login->fresh()->framedipaddress)->toBe('10.20.0.5');
});
