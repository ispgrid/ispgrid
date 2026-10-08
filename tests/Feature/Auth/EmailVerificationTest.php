<?php

use App\Models\User;

it('lets staff in without verifying their email', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

it('has no email verification page', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/email/verify')
        ->assertNotFound();
});
