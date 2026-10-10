<?php

use App\Models\Account;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

it('rebuilds the rows of one customer after a manual edit', function () {
    Account::factory()->create(['username' => 'alice', 'password' => 'secret12']);
    DB::table('radcheck')->insert(['username' => 'alice', 'attribute' => 'Simultaneous-Use', 'op' => ':=', 'value' => '1']);

    $this->artisan('radius:resync', ['username' => 'alice'])
        ->expectsOutput('Rebuilt the rows of alice.')
        ->assertSuccessful();

    expect(DB::table('radcheck')->where('username', 'alice')->orderBy('id')->pluck('attribute')->all())
        ->toBe(['Cleartext-Password', 'Acct-Service-Type', 'Acct-Status-Id', 'Acct-Mac-Type', 'Expiration']);
});

it('rebuilds every plan and every customer, and clears a deleted customer', function () {
    Plan::factory()->create(['slug' => 'home', 'upload' => '10M', 'download' => '10M']);
    Account::factory()->count(2)->create();
    Account::factory()->trashed()->create(['username' => 'gone']);
    DB::table('radcheck')->insert(['username' => 'gone', 'attribute' => 'Acct-Status-Id', 'op' => ':=', 'value' => '1']);

    $this->artisan('radius:resync', ['--all' => true])
        ->expectsOutput('Rebuilt the rows of 4 plans and 3 customers.')
        ->assertSuccessful();

    expect(DB::table('radgroupreply')->where('groupname', 'home')->value('value'))->toBe('10M/10M')
        ->and(DB::table('radusergroup')->count())->toBe(2)
        ->and(DB::table('radcheck')->where('username', 'gone')->count())->toBe(0);
});

it('needs either a username or --all', function (array $arguments) {
    $this->artisan('radius:resync', $arguments)
        ->expectsOutput('Give either a username or --all.')
        ->assertFailed();
})->with([
    'neither' => [[]],
    'both' => [['username' => 'alice', '--all' => true]],
]);

it('says when no customer has the username', function () {
    $this->artisan('radius:resync', ['username' => 'nobody'])
        ->expectsOutput('No customer is called nobody.')
        ->assertFailed();
});
