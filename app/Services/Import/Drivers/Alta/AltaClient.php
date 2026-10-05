<?php

namespace App\Services\Import\Drivers\Alta;

use Illuminate\Support\Facades\Http;

/**
 * The website side, reached through a proxy worker: a suggestions endpoint
 * that turns an id into a product route, and the product page itself, whose
 * __NEXT_DATA__ script carries the same JSON the site renders from.
 */
class AltaClient
{
    public function __construct(protected array $config = [])
    {
    }

    /** The product page route for an external id, or null when it is unknown. */
    public function route(string $externalId): ?string
    {
        $data = $this->get(['type' => 'suggestions', 'query' => $externalId])?->json();

        return $data['products'][0]['route'] ?? null;
    }

    /** The product payload embedded in the page, or null. */
    public function page(string $route): ?array
    {
        $html = $this->get(['type' => 'page', 'route' => $route], 'text/html')?->body();

        if (! $html || ! preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $m)) {
            return null;
        }

        $json = json_decode($m[1], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $initial = $json['props']['pageProps']['initialProductData'] ?? [];

        return empty($initial['product']) ? null : [
            'product'              => $initial['product'],
            'availabilityInStores' => $initial['availabilityInStores'] ?? [],
        ];
    }

    /** Fetch an image through the same worker, so the source only ever sees it. */
    public function image(string $url): ?string
    {
        return $this->get(['type' => 'image', 'url' => $url], '*/*')?->body();
    }

    protected function get(array $query, string $accept = 'application/json')
    {
        $response = Http::timeout($this->config['timeout'] ?? 30)
            ->retry(2, 500, throw: false)
            ->withHeaders([
                'Accept'     => $accept,
                'User-Agent' => config('services.alta.user_agent', 'Mozilla/5.0'),
            ])
            ->get(config('services.alta.worker_url'), $query + ['token' => config('services.alta.token')]);

        return $response->successful() ? $response : null;
    }
}
