<?php

use App\Http\Middleware\SelfcareSession;
use App\Models\SelfcareAccount;
use App\Support\PublicPage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Change the selfcare password. After a staff-set one (must change) the current password is not asked: the
 * customer just logged in with it. This session stays logged in; every other one is logged out on its next request.
 */
new class extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[Computed]
    public function mustChange(): bool
    {
        return (bool) $this->account()->selfcare_must_change;
    }

    public function save(): void
    {
        $account = $this->account();
        $mustChange = (bool) $account->selfcare_must_change;

        $this->validate([
            'current_password' => $mustChange ? ['nullable'] : ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if (! $mustChange && ! Hash::check($this->current_password, (string) $account->selfcare_password)) {
            throw ValidationException::withMessages(['current_password' => __('The current password is wrong.')]);
        }

        if (Hash::check($this->password, (string) $account->selfcare_password)) {
            throw ValidationException::withMessages(['password' => __('Choose a password different from the old one.')]);
        }

        $account->forceFill([
            'selfcare_password' => Hash::make($this->password),
            'selfcare_must_change' => false,
            'selfcare_password_set_at' => now(),
        ])->saveQuietly();

        SelfcareSession::remember($account);
        activity()->performedOn($account)->causedBy($account)->event('selfcare-password-changed')->log('selfcare-password-changed');

        session()->flash('selfcare_message', __('Your password was changed.'));
        $this->redirectRoute('selfcare.dashboard');
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::public', [...PublicPage::data(), 'title' => __('Selfcare password')]);
    }

    private function account(): SelfcareAccount
    {
        return SelfcareSession::account() ?? abort(403);
    }
};
