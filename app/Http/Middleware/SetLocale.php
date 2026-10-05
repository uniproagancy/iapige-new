<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the request language from, in order:
 *   1. a {locale} route parameter  (ready for /en/... prefixed routes)
 *   2. the session                 (set by the locale.switch route)
 *   3. the default language in the database
 * Anything that isn't an active language falls back to the default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $default = Language::defaultCode();
        $route = $request->route();

        $locale = ($route?->parameter('locale'))
            ?? ($request->hasSession() ? $request->session()->get('locale') : null)
            ?? $default;

        if (! Language::isActive($locale)) {
            $locale = $default;
        }

        app()->setLocale($locale);
        app()->setFallbackLocale($default);
        URL::defaults(['locale' => $locale]);

        // controllers shouldn't receive {locale} as their first argument
        if ($route?->hasParameter('locale')) {
            $route->forgetParameter('locale');
        }

        return $next($request);
    }
}
