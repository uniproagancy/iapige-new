<?php

namespace App\Http\Middleware;

use App\Services\Facebook\Pixel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
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
        'feeds/alta-stock.json',
    ];

    protected const BOTS = [
        'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'facebookcatalog',
        'meta-externalagent', 'headless', 'preview', 'monitor', 'curl', 'wget',
        'python-requests', 'go-http-client', 'okhttp', 'axios', 'guzzle',
        'lighthouse', 'pagespeed', 'ahrefs', 'semrush', 'mj12', 'dotbot',
        'petalbot', 'yandex', 'bingpreview', 'whatsapp', 'telegrambot',
    ];

    /* As long as the pixel itself keeps _fbp and _fbc. */
    protected const FBP_DAYS = 90;

    protected const VISITOR_DAYS = 730;

    public function __construct(protected Pixel $pixel) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldTrack($request)) {
            return $next($request);
        }

        if ($this->pixel->enabled()) {
            $this->ensureBrowserIds($request);
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

    /*
     | A visitor arriving for the first time has no _fbp yet: the pixel writes
     | it in the browser, after this page has been sent -- so the very events
     | this page raises on the server (its PageView, a product's ViewContent)
     | went to Meta without it, and an advert's landing page is almost always
     | someone's first. The id is written here instead, in the pixel's own
     | format, and the pixel adopts the cookie it finds rather than making a
     | second one. The click id from an advert is kept the same way, once,
     | rather than invented afresh on every event.
     */
    protected function ensureBrowserIds(Request $request): void
    {
        $now = (int) (microtime(true) * 1000);

        if (! $request->cookie('_fbp')) {
            $this->remember($request, '_fbp', 'fb.1.'.$now.'.'.random_int(1000000000, 9999999999), self::FBP_DAYS, false);
        }

        $click = $request->query('fbclid');

        if (is_string($click) && $click !== '' && ! str_ends_with((string) $request->cookie('_fbc'), '.'.$click)) {
            $this->remember($request, '_fbc', 'fb.1.'.$now.'.'.$click, self::FBP_DAYS, false);
        }

        if (! $this->pixel->visitorKey()) {
            $this->remember($request, Pixel::VISITOR_COOKIE, bin2hex(random_bytes(16)), self::VISITOR_DAYS, true);
        }
    }

    /* Set for this request, so its own events carry it, and for the next. */
    protected function remember(Request $request, string $name, string $value, int $days, bool $httpOnly): void
    {
        $request->cookies->set($name, $value);

        Cookie::queue(Cookie::make($name, $value, $days * 24 * 60, '/', null, $request->isSecure(), $httpOnly, false, 'lax'));
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
