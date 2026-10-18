<?php

namespace App\Http\Middleware;

use App\Services\Facebook\Pixel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TrackPageView
{
    protected const SKIP = [
        'admin', 'admin/*',
        'livewire/*',
        'payment/*',
        'invoice/*',
        'sitemap.xml', 'robots.txt', 'up',
    ];

    protected const BOTS = [
        'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'facebookcatalog',
        'meta-externalagent', 'headless', 'preview', 'monitor', 'curl', 'wget',
        'python-requests', 'go-http-client', 'okhttp', 'axios', 'guzzle',
        'lighthouse', 'pagespeed', 'ahrefs', 'semrush', 'mj12', 'dotbot',
        'petalbot', 'yandex', 'bingpreview', 'whatsapp', 'telegrambot',
    ];

    public function __construct(protected Pixel $pixel) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldTrack($request)) {
            return $next($request);
        }

        $key = 'pixel:pageview:'.md5($request->ip().$request->userAgent().$request->url());
        $eventId = Cache::get($key);

        if (! $eventId) {
            $eventId = Pixel::eventId('pv');
            Cache::put($key, $eventId, now()->addMinutes(30));

            if ($payload = $this->pixel->capture('PageView', [], $eventId)) {
                $this->pixel->dispatchEvent('PageView', $payload);
            }
        }

        view()->share('fbEventId', $eventId);

        return $next($request);
    }

    protected function shouldTrack(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->wantsJson()) {
            return false;
        }

        if ($request->header('X-Livewire') || $request->is(self::SKIP)) {
            return false;
        }

        return ! $this->isBot($request->userAgent());
    }

    protected function isBot(?string $agent): bool
    {

        return blank($agent) || Str::contains(Str::lower($agent), self::BOTS);
    }
}
