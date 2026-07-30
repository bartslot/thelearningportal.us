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
 * On the public marketing pages the URL decides the language, not the visitor's account.
 *
 * /de/lessons must serve German to everyone, including a signed-in teacher whose own interface is
 * Dutch — otherwise the page a search engine indexed is not the page a reader gets. Runs after
 * SetLocale so it overrides that decision, and only where a `locale` route parameter exists.
 */
class SetLocaleFromUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');

        if (is_string($locale) && Locales::isSupported($locale)) {
            App::setLocale($locale);
            Carbon::setLocale($locale);
        }

        return $next($request);
    }
}
