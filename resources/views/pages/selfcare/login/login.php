<?php

use App\Http\Middleware\SelfcareSession;
use App\Models\Account;
use App\Models\SelfcareAccount;
use App\Support\AccountNumbers;
use App\Support\PublicPage;
use App\Support\Selfcare;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The Selfcare login: username, account ID or mobile number, plus the selfcare password (never the PPPoE password).
 * The typed text is looked up in that order: a customer who may use Selfcare with that username; else one with that
 * account ID (with or without the prefix); else every such customer with that mobile number, where the password of
 * any one of them opens a session for all of them (a chooser follows when there are several). One message for every
 * refusal, so the page never says whether anything exists. 5 failed tries a minute per typed text and address, 20 an
 * hour per typed text, 20 an hour per mobile number however it is typed, 50 in 10 minutes per address (generous: many
 * customers may share one address behind NAT). A successful login clears the limits of what was typed.
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
        $typed = trim($this->username);
        $mobile = Selfcare::mobileKey($typed);
        $keys = $this->throttleKeys($typed, $mobile);

        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['username' => __('Too many tries. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
            }
        }

        [$candidates, $byMobile] = $this->candidates($typed, $mobile);
        $account = $candidates->first(fn (SelfcareAccount $candidate): bool => $candidate->selfcare_password !== null
            && Hash::check($this->password, $candidate->selfcare_password));

        if ($account === null) {
            foreach ($keys as $key => [, $decay]) {
                RateLimiter::hit($key, $decay);
            }

            $this->logFailure($typed, $candidates);
            $this->reset('password');

            throw ValidationException::withMessages(['username' => __('Wrong username or password.')]);
        }

        // The address limit stays: other customers behind the same address count toward it.
        foreach (array_keys($keys) as $key) {
            if (! str_starts_with($key, 'selfcare-ip|')) {
                RateLimiter::clear($key);
            }
        }

        Auth::guard(SelfcareSession::GUARD)->login($account);
        session()->regenerate();
        SelfcareSession::remember($account, $byMobile ? $mobile : null);
        $account->forceFill(['selfcare_last_login_at' => now()])->saveQuietly();
        activity()->performedOn($account)->causedBy($account)->event('selfcare-login')->log('selfcare-login');

        $this->redirectRoute($byMobile && $candidates->count() > 1 ? 'selfcare.connections' : 'selfcare.dashboard');
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::public', [...PublicPage::data(), 'title' => __('Selfcare')]);
    }

    /**
     * The customers whose password may open this login, and whether they were found by mobile number.
     *
     * @return array{0: Collection<int, SelfcareAccount>, 1: bool}
     */
    private function candidates(string $typed, ?string $mobile): array
    {
        $eligible = fn (): Builder => SelfcareAccount::query()->where(fn (Builder $query) => Selfcare::scopeEligible($query));
        $byUsername = $eligible()->where('username', $typed)->first();

        if ($byUsername !== null) {
            return [collect([$byUsername]), false];
        }

        $byNumber = $eligible()->whereIn('id', AccountNumbers::matching($typed)->select('id'))->orderBy('id')->first();

        if ($byNumber !== null) {
            return [collect([$byNumber]), false];
        }

        return $mobile === null ? [collect(), false] : [Selfcare::connectionsOnMobile($mobile)->get()->toBase(), true];
    }

    /**
     * The typed text and address key, the typed text key, the address key and, for a mobile number, its key: each
     * with its limit and window in seconds.
     *
     * @return array<string, array{int, int}>
     */
    private function throttleKeys(string $typed, ?string $mobile): array
    {
        $name = Str::lower($typed);
        $ip = (string) request()->ip();
        $keys = ["selfcare|{$name}|{$ip}" => [5, 60], "selfcare|{$name}" => [20, 3600], "selfcare-ip|{$ip}" => [50, 600]];

        return $mobile === null ? $keys : [...$keys, "selfcare-mobile|{$mobile}" => [20, 3600]];
    }

    /**
     * A failed try goes to the activity log of the customer it was meant for: the one with that username or account
     * ID, or each customer with that mobile number. When no hash was checked (nobody found, or nobody with a selfcare
     * password) a dummy hash is checked, so every refusal takes about as long.
     *
     * @param  Collection<int, SelfcareAccount>  $candidates
     */
    private function logFailure(string $typed, Collection $candidates): void
    {
        if ($candidates->every(fn (SelfcareAccount $candidate): bool => $candidate->selfcare_password === null)) {
            Hash::check($this->password, '$2y$12$'.str_repeat('a', 53));
        }

        $named = Account::query()->where('username', $typed)->first();

        foreach ($named === null ? $candidates->take(5) : collect([$named]) as $account) {
            activity()->performedOn($account)->causedBy($account)->event('selfcare-login-failed')->log('selfcare-login-failed');
        }
    }
};
