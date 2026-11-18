<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
 | One address per page. www.iapi.ge and /index.php each answered with a full
 | copy of the shop and their own canonical, so Google saw duplicates of
 | everything. A permanent redirect folds them into the APP_URL form. Other
 | hosts (a server IP, staging, localhost) are left to answer as they do.
 */
class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $canonical = strtolower((string) parse_url($base, PHP_URL_HOST));
        $host = strtolower($request->getHost());
        $uri = $request->getRequestUri();
        $script = preg_match('#\A/index\.php(?=/|\?|\z)#i', $uri) === 1;

        if ($canonical === '' || ($host !== 'www.'.$canonical && ! ($host === $canonical && $script))) {
            return $next($request);
        }

        // only reading requests move; a form posted to the old address would lose its body
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $path = $script ? (preg_replace('#\A/index\.php#i', '', $uri) ?: '/') : $uri;

        return redirect()->to($base.(str_starts_with($path, '/') ? $path : '/'.$path), 301);
    }
}
