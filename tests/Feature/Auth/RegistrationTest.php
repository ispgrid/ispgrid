<?php

use App\Models\User;

it('has no public registration page', function () {
    $this->get('/register')->assertNotFound();
});

it('refuses sign-ups posted to the old registration address', function () {
    $this->post('/register', [
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::query()->where('email', 'mallory@example.com')->exists())->toBeFalse();
});

it('does not offer a sign-up link on the login page', function () {
    $this->get(route('login'))->assertOk()->assertDontSee('Sign up');
});
