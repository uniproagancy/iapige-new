<?php

namespace App\Http\Middleware;

use App\Services\Facebook\Pixel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server half of PageView, and the id the browser half shares with it.
 *
 * Both halves of a pair must carry the same event_id or Meta counts the visit
 * twice, so the id is minted here, handed to the view, and reused for half an
 * hour — a customer refreshing a page is not a second visit.
 */
class TrackPageView
{
    /** Pages where a PageView means nothing, or means somebody else's session. */
    protected const SKIP = [
        'admin', 'admin/*',
        'livewire/*',
        'payment/*',
        'invoice/*',
        'sitemap.xml', 'robots.txt', 'up',
    ];

    /**
     * Crawlers do not run JavaScript, so their visits would arrive from the
     * server only — a stream of one-sided events that looks like real traffic.
     */
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

            // enabled() checks the cookie banner's answer before anything is sent
            $this->pixel->send('PageView', [], $eventId);
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
        // a request with no user agent at all is not a browser
        return blank($agent) || Str::contains(Str::lower($agent), self::BOTS);
    }
}
