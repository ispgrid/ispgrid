<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Setup\FirstRunSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On a fresh install, staff who may change the app settings open the setup page instead of a panel page until it
 * is finished or skipped. Other staff, and every request that is not a page view, pass.
 */
class RedirectToFirstRunSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->user();

        if ($request->isMethod('GET')
            && ! $request->routeIs('setup')
            && $staff instanceof User
            && $staff->can('manage-app-settings')
            && FirstRunSetup::pending()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }
}
