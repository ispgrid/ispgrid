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
 * account ID (with or without the prefix); else every such customer with that mobile number (at most
 * Selfcare::MOBILE_LOGIN_MAX), where the password of any one of them opens a session for all of them (a chooser
 * follows when there are several). One message for every refusal, and every refusal checks as many password hashes
 * (one, or MOBILE_LOGIN_MAX for text that reads as a mobile number), so neither the text nor the time says whether
 * anything exists. Limits: 5 failed tries a minute per typed text and address, 20 an hour per typed text, 20 an hour
 * per customer however it is named (username, account ID or mobile), 20 an hour per mobile number however it is
 * typed, 50 in 10 minutes per address (generous: many customers may share one address behind NAT). A successful login
 * clears the limits it was checked against, apart from the address.
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
        [$candidates, $byMobile] = $this->candidates($typed, $mobile);
        $keys = $this->throttleKeys($typed, $byMobile ? $mobile : null, $candidates);

        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['username' => __('Too many tries. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
            }
        }

        $account = $this->matching($candidates, $mobile !== null);

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
        $this->recordLogin($account);
        SelfcareSession::remember($account, $byMobile ? $mobile : null);
        activity()->performedOn($account)->causedBy($account)->event('selfcare-login')->log('selfcare-login');

        $this->redirectRoute($byMobile && $candidates->count() > 1 ? 'selfcare.connections' : 'selfcare.dashboard');
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::public', [...PublicPage::data(), 'title' => __('Selfcare')]);
    }

    /**
     * The customers whose password may open this login, and whether the typed text is taken as a mobile number (no
     * username or account ID matched and it reads as one, whether or not anyone has that mobile).
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

        return $mobile === null ? [collect(), false] : [Selfcare::connectionsOnMobile($mobile)->toBase(), true];
    }

    /**
     * The candidate whose selfcare password was typed, or null. A refusal checks one hash, or MOBILE_LOGIN_MAX when the
     * typed text reads as a mobile number, filling up with a dummy hash, so the time does not tell how many customers
     * (or whether any) were found.
     *
     * @param  Collection<int, SelfcareAccount>  $candidates
     */
    private function matching(Collection $candidates, bool $readsAsMobile): ?SelfcareAccount
    {
        $checks = 0;

        foreach ($candidates as $candidate) {
            if ($candidate->selfcare_password === null) {
                continue;
            }

            $checks++;

            if (Hash::check($this->password, $candidate->selfcare_password)) {
                return $candidate;
            }
        }

        for ($target = $readsAsMobile ? Selfcare::MOBILE_LOGIN_MAX : 1; $checks < $target; $checks++) {
            // A well-formed bcrypt hash no password matches.
            Hash::check($this->password, '$2y$12$'.str_repeat('a', 53));
        }

        return null;
    }

    /**
     * Saves the login time and, when the hashing settings changed since the password was set, a fresh hash of it (as
     * Laravel's own login does). Done before the session remembers the password, which then stays logged in.
     */
    private function recordLogin(SelfcareAccount $account): void
    {
        $changes = ['selfcare_last_login_at' => now()];

        if (config('hashing.rehash_on_login', true) && Hash::needsRehash((string) $account->selfcare_password)) {
            $changes['selfcare_password'] = Hash::make($this->password);
        }

        $account->forceFill($changes)->saveQuietly();
    }

    /**
     * The typed text and address key, the typed text key, the address key, for a mobile login its key, and a key per
     * customer found (so one customer named four ways still gets 20 tries an hour): each with its limit and window in
     * seconds.
     *
     * @param  Collection<int, SelfcareAccount>  $candidates
     * @return array<string, array{int, int}>
     */
    private function throttleKeys(string $typed, ?string $mobile, Collection $candidates): array
    {
        $name = Str::lower($typed);
        $ip = (string) request()->ip();
        $keys = ["selfcare|{$name}|{$ip}" => [5, 60], "selfcare|{$name}" => [20, 3600], "selfcare-ip|{$ip}" => [50, 600]];

        if ($mobile !== null) {
            $keys["selfcare-mobile|{$mobile}"] = [20, 3600];
        }

        foreach ($candidates as $candidate) {
            $keys["selfcare-account|{$candidate->id}"] = [20, 3600];
        }

        return $keys;
    }

    /**
     * A failed try goes to the activity log of the customers whose password was checked; when there were none, to the
     * customer with that username even if it may not use Selfcare (a Terminated one, say), so staff still see it.
     *
     * @param  Collection<int, SelfcareAccount>  $candidates
     */
    private function logFailure(string $typed, Collection $candidates): void
    {
        $accounts = $candidates->isNotEmpty() ? $candidates : Account::query()->where('username', $typed)->limit(1)->get();

        foreach ($accounts as $account) {
            activity()->performedOn($account)->causedBy($account)->event('selfcare-login-failed')->log('selfcare-login-failed');
        }
    }
};
