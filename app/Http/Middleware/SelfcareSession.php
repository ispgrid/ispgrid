<?php

namespace App\Http\Middleware;

use App\Models\SelfcareAccount;
use App\Support\PublicPage;
use App\Support\Selfcare;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every Selfcare page after login. Not found while Selfcare is off. The session has an open connection (the guard's
 * user) and a login account, the one whose selfcare password was typed: the same customer after a username or
 * account ID login; after a mobile login, any connection on that mobile may be open. Logs the customer out when the
 * login account may no longer use Selfcare or its selfcare password changed since (staff set one, or it was changed
 * on another device), when the open connection may no longer use Selfcare, when in a mobile session either of them
 * no longer has that mobile, or after 30 idle minutes. While the login account's staff-set password waits to be
 * replaced, only the password page opens. Runs again on every Livewire request of these pages.
 */
class SelfcareSession
{
    public const GUARD = 'selfcare';

    /** A fingerprint of the login account's password hash (any new password changes the hash). */
    public const FINGERPRINT = 'selfcare.fingerprint';

    /** The account whose password was typed; missing in sessions from before mobile logins (the open one). */
    public const LOGIN_ID = 'selfcare.login_id';

    /** The mobile number (its last digits, Selfcare::mobileKey()) of a mobile login; missing otherwise. */
    public const MOBILE = 'selfcare.mobile';

    public const LAST_SEEN = 'selfcare.last_seen';

    public const IDLE_MINUTES = 30;

    /** The request attribute holding the account read fresh in this request. */
    private const FRESH = 'selfcare.fresh_account';

    /**
     * Routes that open while a staff-set password waits to be replaced.
     */
    private const OPEN_WHILE_MUST_CHANGE = ['selfcare.password', 'selfcare.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        self::abortUnlessOpen();

        $account = self::account();

        if ($account === null) {
            return self::forget(__('Log in to continue.'));
        }

        $login = self::loginAccount();

        if ($login === null || ! Selfcare::eligible($login) || $login->selfcare_password === null
            || $request->session()->get(self::FINGERPRINT) !== self::fingerprint($login)
            || ! Selfcare::eligible($account) || ! self::sameSession($login, $account)) {
            return self::forget(__('You were logged out. Log in again.'));
        }

        if (now()->getTimestamp() - (int) $request->session()->get(self::LAST_SEEN, 0) > self::IDLE_MINUTES * 60) {
            return self::forget(__('You were logged out after 30 minutes without activity.'));
        }

        $request->session()->put(self::LAST_SEEN, now()->getTimestamp());

        if ($login->selfcare_must_change && ! in_array($request->route()?->getName(), self::OPEN_WHILE_MUST_CHANGE, true)) {
            return redirect()->route('selfcare.password');
        }

        return $next($request);
    }

    /**
     * The open connection, read again from the database once per request (deleted ones included, so the checks
     * can see it); the page reuses what this middleware read.
     */
    public static function account(): ?SelfcareAccount
    {
        $guard = Auth::guard(self::GUARD);
        $account = $guard->user();
        $attributes = request()->attributes;

        if ($account instanceof SelfcareAccount && $attributes->get(self::FRESH) === $account) {
            return $account;
        }

        $fresh = $account instanceof SelfcareAccount ? $account->fresh() : null;

        if ($fresh !== null) {
            $guard->setUser($fresh);
            $attributes->set(self::FRESH, $fresh);
        }

        return $fresh;
    }

    /**
     * The account whose selfcare password this session logged in with: its password is the one the customer
     * confirms and changes, whichever connection is open.
     */
    public static function loginAccount(): ?SelfcareAccount
    {
        $account = self::account();
        $id = session(self::LOGIN_ID);

        if ($account === null || $id === null || (int) $id === $account->id) {
            return $account;
        }

        return SelfcareAccount::withTrashed()->find((int) $id);
    }

    /**
     * The connections this session may open: every eligible one on its mobile, or just the open one.
     *
     * @return Collection<int, SelfcareAccount>
     */
    public static function connections(): Collection
    {
        $account = self::account();
        $mobile = session(self::MOBILE);

        if ($account === null) {
            return new Collection;
        }

        return $mobile === null ? new Collection([$account]) : Selfcare::connectionsOnMobile((string) $mobile)->get();
    }

    /**
     * The plain "not available" page while Selfcare is off, with a 404 status.
     */
    public static function abortUnlessOpen(): void
    {
        if (! Selfcare::isOpen()) {
            abort(response()->view('selfcare.closed', PublicPage::data(), 404));
        }
    }

    /**
     * Ties this session to the account whose password was typed (and, for a mobile login, to the mobile) and starts
     * the idle clock.
     */
    public static function remember(SelfcareAccount $account, ?string $mobile = null): void
    {
        session()->put(self::LOGIN_ID, $account->id);
        session()->put(self::FINGERPRINT, self::fingerprint($account));
        session()->put(self::LAST_SEEN, now()->getTimestamp());
        $mobile === null ? session()->forget(self::MOBILE) : session()->put(self::MOBILE, $mobile);
    }

    /**
     * Keeps this session logged in after the login account's own password change.
     */
    public static function rememberPassword(SelfcareAccount $login): void
    {
        session()->put(self::FINGERPRINT, self::fingerprint($login));
    }

    /**
     * Logs the customer out of Selfcare only: a staff login in the same browser stays.
     */
    public static function forget(?string $message = null): RedirectResponse
    {
        Auth::guard(self::GUARD)->logout();
        session()->forget([self::FINGERPRINT, self::LOGIN_ID, self::MOBILE, self::LAST_SEEN]);
        session()->regenerateToken();

        $redirect = redirect()->route('selfcare.login');

        return $message === null ? $redirect : $redirect->with('selfcare_message', $message);
    }

    /**
     * The open connection belongs to this login: the same customer, or in a mobile session both still on that mobile.
     */
    private static function sameSession(SelfcareAccount $login, SelfcareAccount $account): bool
    {
        $mobile = session(self::MOBILE);

        if ($mobile === null) {
            return $login->id === $account->id;
        }

        return Selfcare::hasMobile($login, (string) $mobile) && Selfcare::hasMobile($account, (string) $mobile);
    }

    private static function fingerprint(SelfcareAccount $account): string
    {
        return hash_hmac('sha256', (string) $account->selfcare_password, (string) config('app.key'));
    }
}
