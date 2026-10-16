<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

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

        if ($route?->hasParameter('locale')) {
            $route->forgetParameter('locale');
        }

        return $next($request);
    }
}
