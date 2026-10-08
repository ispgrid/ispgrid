<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Passkeys;

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

it('does not recognise the passkey of a disabled staff member', function () {
    $staff = User::factory()->create();
    $staff->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-123', 'credential' => ['id' => 'credential-123']]);
    $findPasskey = fn () => Passkeys::passkeyModel()::query()->where('credential_id', 'credential-123')->first();

    expect($findPasskey())->not->toBeNull();

    $staff->delete();

    expect($findPasskey())->toBeNull();
});

it('does not bring back an old remember-me cookie when a disabled staff member is restored', function () {
    $staff = User::factory()->create(['remember_token' => 'remember-token-123']);

    $staff->delete();
    $staff->restore();

    expect(Auth::guard('web')->getProvider()->retrieveByToken($staff->id, 'remember-token-123'))->toBeNull()
        ->and(Auth::guard('web')->getProvider()->retrieveById($staff->id))->not->toBeNull();
});

it('ends the database sessions of a disabled staff member', function () {
    config(['session.driver' => 'database']);
    $staff = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'session-123',
        'user_id' => $staff->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => time(),
    ]);

    $staff->delete();

    expect(DB::table('sessions')->where('user_id', $staff->id)->count())->toBe(0);
});
