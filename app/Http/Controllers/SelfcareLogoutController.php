<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SelfcareSession;
use Illuminate\Http\RedirectResponse;

/**
 * Logs the customer out of Selfcare. A staff login in the same browser stays.
 */
class SelfcareLogoutController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        SelfcareSession::abortUnlessOpen();

        return SelfcareSession::forget();
    }
}
