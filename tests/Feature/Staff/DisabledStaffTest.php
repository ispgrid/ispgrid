<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('no longer finds a disabled staff member for an open session or a remember-me cookie', function () {
    $staff = User::factory()->create(['remember_token' => 'remember-token-123']);
    $provider = Auth::guard('web')->getProvider();

    $staff->delete();

    expect($provider->retrieveById($staff->id))->toBeNull()
        ->and($provider->retrieveByToken($staff->id, 'remember-token-123'))->toBeNull();
});

it('refuses to log in a disabled staff member', function () {
    $staff = User::factory()->trashed()->create();

    $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
