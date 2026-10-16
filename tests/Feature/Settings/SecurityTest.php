<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);
        Features::passkeys([
            'confirmPassword' => true,
        ]);
    }

    public function test_security_settings_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'));

        $response->assertOk();

        $response->assertSee('Passkeys');
        $response->assertSee('No passkeys yet');
        $response->assertSee('Two-factor authentication');
        $response->assertSee('Enable 2FA');
    }

    public function test_security_settings_page_requires_password_confirmation_when_enabled(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('security.edit'));

        $response->assertRedirect(route('password.confirm'));
    }

    public function test_security_settings_page_renders_without_two_factor_when_feature_is_disabled(): void
    {
        config(['fortify.features' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Update password')
            ->assertDontSee('Manage your passkeys for passwordless sign-in')
            ->assertDontSee('Add a passkey to sign in without a password')
            ->assertDontSee('Two-factor authentication');
    }

    public function test_two_factor_authentication_disabled_when_confirmation_abandoned_between_requests(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->actingAs($user);

        $component = Livewire::test('pages::settings.security');

        $component->assertSet('twoFactorEnabled', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $response->assertHasNoErrors();
        $response->assertDispatched('toast', message: 'Password updated.');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_changing_the_password_logs_out_the_other_devices_only(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['password' => Hash::make('password'), 'remember_token' => 'old-remember-token']);
        $this->actingAs($user);
        $row = ['user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()];
        DB::table('sessions')->insert([['id' => 'other-device'] + $row, ['id' => session()->getId()] + $row]);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertSame([session()->getId()], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());
        $this->assertNotSame('old-remember-token', $user->refresh()->remember_token);
    }

    /**
     * A confirmed 2FA user whose password confirmation ran out while the page stayed open.
     */
    private function staleConfirmation(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->actingAs($user);
        session(['auth.password_confirmed_at' => time() - config('auth.password_timeout') - 1]);

        return $user;
    }

    public function test_disabling_two_factor_asks_for_the_password_again_once_the_confirmation_ran_out(): void
    {
        $user = $this->staleConfirmation();

        Livewire::test('pages::settings.security')->call('disable')->assertRedirect(route('password.confirm'));

        $this->assertNotNull($user->refresh()->two_factor_secret);
    }

    public function test_two_factor_can_be_disabled_while_the_confirmation_is_fresh(): void
    {
        $user = $this->staleConfirmation();
        session(['auth.password_confirmed_at' => time()]);

        Livewire::test('pages::settings.security')->call('disable')->assertNoRedirect();

        $this->assertNull($user->refresh()->two_factor_secret);
    }

    public function test_deleting_a_passkey_asks_for_the_password_again_once_the_confirmation_ran_out(): void
    {
        $user = $this->staleConfirmation();
        $passkeyId = DB::table('passkeys')->insertGetId([
            'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages::settings.security')
            ->call('confirmDelete', $passkeyId)
            ->call('deletePasskey')
            ->assertRedirect(route('password.confirm'));

        $this->assertDatabaseHas('passkeys', ['id' => $passkeyId]);
    }

    public function test_new_recovery_codes_ask_for_the_password_again_once_the_confirmation_ran_out(): void
    {
        $user = $this->staleConfirmation();
        $codes = $user->two_factor_recovery_codes;

        Livewire::test('pages::settings.two-factor.recovery-codes')
            ->call('regenerateRecoveryCodes')
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($codes, $user->refresh()->two_factor_recovery_codes);
    }

    public function test_starting_two_factor_setup_asks_for_the_password_again_once_the_confirmation_ran_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        session(['auth.password_confirmed_at' => time() - config('auth.password_timeout') - 1]);

        Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
            ->call('startTwoFactorSetup')
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($user->refresh()->two_factor_secret);
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.security')
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $response->assertHasErrors(['current_password']);
    }
}
