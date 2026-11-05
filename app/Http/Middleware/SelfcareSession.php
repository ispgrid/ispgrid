<?php

namespace App\Http\Middleware;

use App\Models\SelfcareAccount;
use App\Support\PublicPage;
use App\Support\Selfcare;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every Selfcare page after login. Not found while Selfcare is off. Logs the customer out when they may no longer use
 * Selfcare (Terminated, deleted, no longer PPPoE or IPoE), when their selfcare password changed since this session
 * logged in (staff set one, or they changed it on another device), or after 30 idle minutes. While a staff-set
 * password waits to be replaced, only the password page opens. Runs again on every Livewire request of these pages.
 */
class SelfcareSession
{
    public const GUARD = 'selfcare';

    /** A fingerprint of the password hash this session logged in with (any new password changes the hash). */
    public const FINGERPRINT = 'selfcare.fingerprint';

    public const LAST_SEEN = 'selfcare.last_seen';

    public const IDLE_MINUTES = 30;

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

        if (! Selfcare::eligible($account) || $account->selfcare_password === null || $request->session()->get(self::FINGERPRINT) !== self::fingerprint($account)) {
            return self::forget(__('You were logged out. Log in again.'));
        }

        if (now()->getTimestamp() - (int) $request->session()->get(self::LAST_SEEN, 0) > self::IDLE_MINUTES * 60) {
            return self::forget(__('You were logged out after 30 minutes without activity.'));
        }

        $request->session()->put(self::LAST_SEEN, now()->getTimestamp());

        if ($account->selfcare_must_change && ! in_array($request->route()?->getName(), self::OPEN_WHILE_MUST_CHANGE, true)) {
            return redirect()->route('selfcare.password');
        }

        return $next($request);
    }

    /**
     * The logged-in customer, read again from the database (deleted ones included, so the checks can see it).
     */
    public static function account(): ?SelfcareAccount
    {
        $guard = Auth::guard(self::GUARD);
        $account = $guard->user();
        $fresh = $account instanceof SelfcareAccount ? $account->fresh() : null;

        if ($fresh !== null) {
            $guard->setUser($fresh);
        }

        return $fresh;
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
     * Ties this session to the account's current selfcare password and starts the idle clock.
     */
    public static function remember(SelfcareAccount $account): void
    {
        session()->put(self::FINGERPRINT, self::fingerprint($account));
        session()->put(self::LAST_SEEN, now()->getTimestamp());
    }

    /**
     * Logs the customer out of Selfcare only: a staff login in the same browser stays.
     */
    public static function forget(?string $message = null): RedirectResponse
    {
        Auth::guard(self::GUARD)->logout();
        session()->forget([self::FINGERPRINT, self::LAST_SEEN]);
        session()->regenerateToken();

        $redirect = redirect()->route('selfcare.login');

        return $message === null ? $redirect : $redirect->with('selfcare_message', $message);
    }

    private static function fingerprint(SelfcareAccount $account): string
    {
        return hash_hmac('sha256', (string) $account->selfcare_password, (string) config('app.key'));
    }
}
