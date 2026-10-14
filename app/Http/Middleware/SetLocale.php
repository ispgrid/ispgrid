<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the panel in the staff member's own language, else the language in App settings, else English.
 * A language the panel does not ship is skipped. Carbon follows the app locale, so relative dates change too.
 */
class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::resolve($request->user()?->locale));

        return $next($request);
    }

    /**
     * The given language if the panel ships it, else the App settings language if it does, else English.
     * App settings are read only when the given language does not decide it.
     */
    public static function resolve(?string $preferred = null): string
    {
        if (self::ships($preferred)) {
            return $preferred;
        }

        $appLocale = AppSetting::valueOf('locale');

        return self::ships($appLocale) ? $appLocale : config('app.fallback_locale');
    }

    /**
     * @phpstan-assert-if-true string $locale
     */
    private static function ships(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, config('app.locales'));
    }
}
