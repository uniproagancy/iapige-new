<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WarrantyPublicHeaders
{
    public function handle(Request $request, Closure $next): mixed
    {
        $headers = ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY'];
        try {
            $response = $next($request);
            $response->headers->add($headers);
            return $response;
        } catch (HttpExceptionInterface $error) {
            // Preserve privacy headers on expired, revoked and otherwise unavailable links too.
            throw new HttpException($error->getStatusCode(), $error->getMessage(), $error,
                array_merge($error->getHeaders(), $headers));
        }
    }
}
