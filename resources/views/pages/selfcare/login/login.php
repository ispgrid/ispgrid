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
 * 20 an hour per username, 50 in 10 minutes per address (generous: many customers may share one address behind NAT).
 * A successful login clears the username limits.
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

        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['username' => __('Too many tries. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
            }
        }

        $guard = Auth::guard(SelfcareSession::GUARD);

        if (! $guard->attempt(['username' => $username, 'password' => $this->password, fn (Builder $query) => Selfcare::scopeEligible($query)])) {
            foreach ($keys as $key => [, $decay]) {
                RateLimiter::hit($key, $decay);
            }

            $this->logFailure($username);
            $this->reset('password');

            throw ValidationException::withMessages(['username' => __('Wrong username or password.')]);
        }

        /** @var SelfcareAccount $account */
        $account = $guard->user();
        // The address limit stays: other customers behind the same address count toward it.
        foreach (array_slice(array_keys($keys), 0, 2) as $key) {
            RateLimiter::clear($key);
        }

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
     * The username and address key, the username key and the address key, each with its limit and window in seconds.
     *
     * @return array<string, array{int, int}>
     */
    private function throttleKeys(string $username): array
    {
        $name = Str::lower($username);
        $ip = (string) request()->ip();

        return ["selfcare|{$name}|{$ip}" => [5, 60], "selfcare|{$name}" => [20, 3600], "selfcare-ip|{$ip}" => [50, 600]];
    }

    /**
     * A failed try on a known username goes to that customer's activity log. When the guard checked no hash (an
     * unknown username, a customer who may not use Selfcare, or no selfcare password yet) a dummy hash is checked,
     * so every refusal takes about as long.
     */
    private function logFailure(string $username): void
    {
        $account = Account::query()->where('username', $username)->first();

        if ($account === null || ! Selfcare::eligible($account) || $account->selfcare_password === null) {
            Hash::check($this->password, '$2y$12$'.str_repeat('a', 53));
        }

        if ($account !== null) {
            activity()->performedOn($account)->causedBy($account)->event('selfcare-login-failed')->log('selfcare-login-failed');
        }
    }
};
