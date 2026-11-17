<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;

// Access guards must run before implicit model binding, including when tables are not installed yet.
class EnsureWarrantyEnabled implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next, string $mode = 'staff'): mixed
    {
        if ($mode === 'public') {
            return app(WarrantyPublicHeaders::class)->handle($request,
                fn (Request $request) => $this->guard($request, $next, $mode));
        }

        return $this->guard($request, $next, $mode);
    }

    private function guard(Request $request, Closure $next, string $mode): mixed
    {
        abort_unless(config('warranty.enabled'), 404);
        if ($mode === 'public') {
            abort_unless(config('warranty.public_enabled'), 404);
        }
        if ($mode === 'agent') {
            abort_unless(config('warranty.agent_enabled'), 404);
        }
        if ($mode === 'staff') {
            abort_unless($request->user()?->isStaff(), 403);
        }
        if ($mode === 'owner') {
            abort_unless($request->user()?->isAdmin(), 403);
        }

        return $next($request);
    }
}
