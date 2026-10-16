<?php

namespace App\Concerns;

/**
 * For actions on a page behind password.confirm: the route checks the confirmation only when the page opens, so
 * an action on a page left open checks it again.
 */
trait ConfirmsPasswordAgain
{
    /**
     * True while the password confirmation is fresh. Otherwise sends the user to confirm it again (then back to
     * the security page) and returns false: the action must stop.
     */
    protected function passwordStillConfirmed(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        if (time() - $confirmedAt < (int) config('auth.password_timeout', 10800)) {
            return true;
        }

        session()->put('url.intended', route('security.edit'));
        $this->redirectRoute('password.confirm');

        return false;
    }
}
