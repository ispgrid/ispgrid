<?php

use App\Models\User;

it('loads Livewire and Alpine on pages without a Livewire component', function (string $page) {
    $user = User::factory()->create();

    $response = $page === 'login'
        ? $this->get(route('login'))
        : $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()->assertSee('window.livewireScriptConfig', false);
})->with(['login', 'dashboard']);
