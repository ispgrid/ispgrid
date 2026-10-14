<?php

use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\Nas;
use App\Models\Radacct;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Nas::factory()->create(['nasname' => '10.0.0.1', 'shortname' => 'core']);
    Nas::factory()->create(['nasname' => '10.0.0.2', 'shortname' => 'edge']);
});

it('sends guests to the login page', function () {
    auth()->logout();

    $this->get(route('online.index'))->assertRedirect(route('login'));
});

it('lists the open sessions with their customer and router', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    Radacct::factory()->create(['username' => 'alice', 'nasipaddress' => '10.0.0.1', 'framedipaddress' => '10.10.10.5']);
    Radacct::factory()->create(['username' => 'labuser', 'nasipaddress' => '10.0.0.2']);
    Radacct::factory()->closed()->create(['username' => 'gone']);

    $this->get(route('online.index'))
        ->assertOk()
        ->assertSee(route('customers.show', $account->id))
        ->assertSee('10.10.10.5')
        ->assertSee('core')
        ->assertSee('labuser')
        ->assertDontSee('gone');
});

it('filters by router and finds a session by username, IP or MAC', function (string $filter, string $value, string $shown) {
    Radacct::factory()->create(['username' => 'alice', 'nasipaddress' => '10.0.0.1', 'framedipaddress' => '10.10.10.5', 'callingstationid' => 'AA:AA:AA:AA:AA:01']);
    Radacct::factory()->create(['username' => 'bob', 'nasipaddress' => '10.0.0.2', 'framedipaddress' => '10.10.10.6', 'callingstationid' => 'AA:AA:AA:AA:AA:02']);

    $hidden = $shown === 'alice' ? 'bob' : 'alice';

    Livewire::test('pages::online.index')
        ->set($filter, $value)
        ->assertSee($shown)
        ->assertDontSee($hidden);
})->with([
    'router' => ['router', '10.0.0.2', 'bob'],
    'username' => ['search', 'ali', 'alice'],
    'IP' => ['search', '10.10.10.6', 'bob'],
    'MAC' => ['search', 'AA:AA:AA:AA:AA:01', 'alice'],
]);

it('disconnects one session and logs who did it', function () {
    Queue::fake();
    $account = Account::factory()->create(['username' => 'alice']);
    $session = Radacct::factory()->create(['username' => 'alice', 'nasipaddress' => '10.0.0.1', 'framedipaddress' => '10.10.10.5']);

    Livewire::test('pages::online.index')
        ->call('disconnect', $session->radacctid)
        ->assertDispatched('toast', message: 'Disconnect sent to the NAS. The session closes when the NAS confirms.', variant: 'success');

    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->username === 'alice' && $job->sessionId === $session->radacctid);
    $entry = Activity::query()->where('event', 'disconnected')->sole();
    expect($entry->subject_id)->toBe($account->id)
        ->and($entry->attribute_changes->toArray()['attributes'])->toBe(['sessions' => '10.0.0.1 10.10.10.5']);
});

it('says when the session has already ended', function () {
    Queue::fake();
    $session = Radacct::factory()->closed()->create();

    Livewire::test('pages::online.index')
        ->call('disconnect', $session->radacctid)
        ->assertDispatched('toast', message: 'This session has already ended.', variant: 'success');

    Queue::assertNothingPushed();
});
