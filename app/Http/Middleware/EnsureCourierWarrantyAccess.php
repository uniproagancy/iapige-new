<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;

class EnsureCourierWarrantyAccess implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(config('warranty.enabled'), 404);
        abort_unless($request->user()?->isCourier(), 403);

        return $next($request);
    }
}
