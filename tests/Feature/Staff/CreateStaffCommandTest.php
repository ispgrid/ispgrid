<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a staff login from the command line', function () {
    $this->artisan('staff:create', [
        '--name' => 'First Admin',
        '--email' => 'admin@isp.example',
        '--password' => 'secret-pass-123',
    ])->expectsOutput('Staff member admin@isp.example created.')
        ->assertSuccessful();

    expect(User::query()->where('email', 'admin@isp.example')->exists())->toBeTrue();
});

it('prints the validation errors and fails', function () {
    User::factory()->create(['email' => 'admin@isp.example']);

    $this->artisan('staff:create', [
        '--name' => 'Again',
        '--email' => 'admin@isp.example',
        '--password' => 'secret-pass-123',
    ])->expectsOutput('The email has already been taken.')
        ->assertFailed();
});

it('resets the password of an existing staff member', function () {
    $staff = User::factory()->create(['email' => 'admin@isp.example']);

    $this->artisan('staff:create', [
        '--email' => 'admin@isp.example',
        '--password' => 'new-secret-pass-456',
        '--reset' => true,
    ])->expectsOutput('Password reset for admin@isp.example.')
        ->assertSuccessful();

    expect(Hash::check('new-secret-pass-456', $staff->fresh()->password))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('refuses to reset the password of someone who is not active staff', function (bool $disabled) {
    if ($disabled) {
        User::factory()->trashed()->create(['email' => 'gone@isp.example']);
    }

    $this->artisan('staff:create', [
        '--email' => 'gone@isp.example',
        '--password' => 'new-secret-pass-456',
        '--reset' => true,
    ])->expectsOutput('No active staff member uses gone@isp.example. Restore them on the Staff screen first.')
        ->assertFailed();
})->with(['unknown email' => false, 'disabled staff member' => true]);

it('logs out remembered devices when it resets a password', function () {
    $staff = User::factory()->create(['email' => 'admin@isp.example', 'remember_token' => 'remember-token-123']);

    $this->artisan('staff:create', [
        '--email' => 'admin@isp.example',
        '--password' => 'new-secret-pass-456',
        '--reset' => true,
    ])->assertSuccessful();

    expect($staff->fresh()->remember_token)->not->toBe('remember-token-123');
});
