<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply the signed-in user's UI language per request (e.g. Dutch pilot teachers with locale 'nl').
 * Falls back to the app default for guests or unsupported values. Also drives Carbon's relative
 * dates ("3 dagen geleden") so date strings match the chosen locale.
 *
 * The list of languages lives in App\Support\Locales.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;
        if (! Locales::isSupported($locale)) {
            // Guests have no stored locale, so negotiate from the browser's Accept-Language — this
            // lets a Dutch teacher see the login page in Dutch before signing in. Falls back to the
            // first supported locale ('en') when the browser doesn't prefer a supported one.
            $locale = $request->getPreferredLanguage(Locales::codes()) ?? config('app.locale', 'en');
        }

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
