<?php

namespace App\Http\Middleware;

use App\Models\WarrantyAgentKey;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;

class AuthenticateWarrantyAgent implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next, string $scope = 'read'): mixed
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/\Aiw_[a-f0-9]{64}\z/D', $token), 401);
        $key = WarrantyAgentKey::query()->where('token_hash', hash('sha256', $token))->first();
        abort_unless($key && $key->permits($scope) && $key->owner?->isAdmin(), 403);
        if (! $request->isMethod('GET')) {
            abort_unless($request->isJson(), 415);
        }
        $key->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('warranty_agent_key', $key);
        $request->setUserResolver(fn () => $key->owner);

        return $next($request);
    }
}
