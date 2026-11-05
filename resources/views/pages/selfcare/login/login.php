<?php

use App\Http\Middleware\SelfcareSession;
use App\Models\Account;
use App\Models\SelfcareAccount;
use App\Support\PublicPage;
use App\Support\Selfcare;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The Selfcare login: username plus the selfcare password (never the PPPoE password). One message for every
 * refusal, so the page never says whether a username exists. 5 failed tries a minute per username and address,
 * 20 an hour per username.
 */
new class extends Component
{
    public string $username = '';

    public string $password = '';

    public function mount(): void
    {
        SelfcareSession::abortUnlessOpen();

        if (Auth::guard(SelfcareSession::GUARD)->check()) {
            $this->redirectRoute('selfcare.dashboard');
        }
    }

    public function login(): void
    {
        SelfcareSession::abortUnlessOpen();
        $this->validate(['username' => ['required', 'string', 'max:64'], 'password' => ['required', 'string', 'max:255']]);
        $username = trim($this->username);
        $keys = $this->throttleKeys($username);

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['username' => __('Too many tries. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
            }
        }

        $guard = Auth::guard(SelfcareSession::GUARD);

        if (! $guard->attempt(['username' => $username, 'password' => $this->password, fn (Builder $query) => Selfcare::scopeEligible($query)])) {
            RateLimiter::hit(array_key_first($keys), 60);
            RateLimiter::hit(array_key_last($keys), 3600);
            $this->logFailure($username);
            $this->reset('password');

            throw ValidationException::withMessages(['username' => __('Wrong username or password.')]);
        }

        /** @var SelfcareAccount $account */
        $account = $guard->user();
        session()->regenerate();
        SelfcareSession::remember($account);
        $account->forceFill(['selfcare_last_login_at' => now()])->saveQuietly();
        activity()->performedOn($account)->causedBy($account)->event('selfcare-login')->log('selfcare-login');

        $this->redirectRoute('selfcare.dashboard');
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::public', [...PublicPage::data(), 'title' => __('Selfcare')]);
    }

    /**
     * The per-minute key (username and address) and the per-hour key (username), each with its limit.
     *
     * @return array<string, int>
     */
    private function throttleKeys(string $username): array
    {
        $name = Str::lower($username);

        return ["selfcare|{$name}|".request()->ip() => 5, "selfcare|{$name}" => 20];
    }

    /**
     * A failed try on a known username goes to that customer's activity log; an unknown one is checked against a
     * dummy hash, so both take about as long.
     */
    private function logFailure(string $username): void
    {
        $account = Account::query()->where('username', $username)->first();

        if ($account === null) {
            Hash::check($this->password, '$2y$12$'.str_repeat('a', 53));

            return;
        }

        activity()->performedOn($account)->causedBy($account)->event('selfcare-login-failed')->log('selfcare-login-failed');
    }
};
