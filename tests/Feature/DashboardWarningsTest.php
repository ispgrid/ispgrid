<?php

use App\Models\Nas;
use App\Models\User;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('warns about each router too old to send Message-Authenticator', function () {
    Nas::factory()->legacy()->create(['shortname' => 'old-tower', 'nasname' => '10.0.0.7']);
    Nas::factory()->create(['shortname' => 'new-core']);
    Nas::factory()->legacy()->trashed()->create(['shortname' => 'gone']);

    Livewire::test('pages::dashboard')
        ->assertSee('old-tower (10.0.0.7)')
        ->assertSee('RouterOS 6.49.17 is affected by the Blast-RADIUS security issue')
        ->assertDontSee('new-core')
        ->assertDontSee('gone');
});

it('shows no warning when every router is current', function () {
    Nas::factory()->create();

    Livewire::test('pages::dashboard')->assertDontSee('Blast-RADIUS');
});
