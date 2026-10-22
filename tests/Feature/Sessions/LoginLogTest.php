<?php

use App\Models\Account;
use App\Models\Nas;
use App\Models\Radpostauth;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Nas::factory()->create(['nasname' => '10.0.0.1', 'shortname' => 'core']);
});

it('sends guests to the login page', function () {
    auth()->logout();

    $this->get(route('login-log.index'))->assertRedirect(route('login'));
});

it('lists login attempts newest first, with the result and the real reason', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    Radpostauth::factory()->create(['username' => 'alice', 'authdate' => '2026-10-10 09:00:00', 'nasipaddress' => '10.0.0.1']);
    Radpostauth::factory()->rejected('Account is suspended')->create(['username' => 'alice', 'authdate' => '2026-10-10 10:00:00']);

    $this->get(route('login-log.index'))
        ->assertOk()
        ->assertSeeInOrder(['Account is suspended', 'Accepted'])
        ->assertSee(route('customers.show', $account->id))
        ->assertSee('core');
});

it('filters by result', function (string $result, string $shown) {
    Radpostauth::factory()->create(['username' => 'accepteduser']);
    Radpostauth::factory()->rejected()->create(['username' => 'rejecteduser']);
    Radpostauth::factory()->dropped()->create(['username' => 'droppeduser']);

    $page = Livewire::test('pages::login-log.index')->set('result', $result)->assertSee($shown);

    foreach (array_diff(['accepteduser', 'rejecteduser', 'droppeduser'], [$shown]) as $hidden) {
        $page->assertDontSee($hidden);
    }
})->with([
    ['accepted', 'accepteduser'],
    ['rejected', 'rejecteduser'],
    ['dropped', 'droppeduser'],
]);

it('filters by username, reason and router', function (string $filter, string $value) {
    Radpostauth::factory()->rejected('Wrong Password')->create(['username' => 'alice', 'nasipaddress' => '10.0.0.1']);
    Radpostauth::factory()->rejected('No plan assigned')->create(['username' => 'bob', 'nasipaddress' => '10.0.0.2']);

    Livewire::test('pages::login-log.index')
        ->set($filter, $value)
        ->assertSee('alice')
        ->assertDontSee('bob');
})->with([
    'username' => ['username', 'ali'],
    'reason' => ['reason', 'password'],
    'router' => ['router', '10.0.0.1'],
]);

it('shows the IP a login was given and finds logins by it', function () {
    Radpostauth::factory()->create(['username' => 'alice', 'framedipaddress' => '10.20.0.7']);
    Radpostauth::factory()->create(['username' => 'bob', 'framedipaddress' => '10.20.0.70']);

    Livewire::test('pages::login-log.index')
        ->set('ip', ' 10.20.0.7 ')
        ->assertSee(['alice', '10.20.0.7'])
        ->assertDontSee('bob');
});

it('ignores a result it does not know', function () {
    Radpostauth::factory()->create(['username' => 'alice']);

    Livewire::test('pages::login-log.index')->set('result', 'everything')->assertSee('alice');
});
