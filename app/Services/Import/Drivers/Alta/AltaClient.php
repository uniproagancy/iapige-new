<?php

namespace App\Services\Import\Drivers\Alta;

use Illuminate\Support\Facades\Http;

class AltaClient
{
    public function __construct(protected array $config = []) {}

    public function route(string $externalId): ?string
    {
        $data = $this->get(['type' => 'suggestions', 'query' => $externalId])?->json();

        return $data['products'][0]['route'] ?? null;
    }

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
            'product' => $initial['product'],
            'availabilityInStores' => $initial['availabilityInStores'] ?? [],
        ];
    }

    public function image(string $url): ?string
    {
        return $this->get(['type' => 'image', 'url' => $url], '*/*')?->body();
    }

    protected const BLOCKED_STATUSES = [401, 403, 429, 503];

    protected function get(array $query, string $accept = 'application/json')
    {
        $response = Http::timeout($this->config['timeout'] ?? config('shop.import_request_timeout'))
            ->retry(2, 500, throw: false)
            ->withHeaders([
                'Accept' => $accept,
                'User-Agent' => config('services.alta.user_agent', 'Mozilla/5.0'),
            ])
            ->get(config('services.alta.worker_url'), $query + array_filter([
                'token' => config('services.alta.token'),

                'cfClearance' => config('services.alta.cf_clearance'),
            ]));

        $body = $response->body();

        if (in_array($response->status(), self::BLOCKED_STATUSES, true) || $this->isChallenge($body)) {
            throw new BlockedByAlta($this->refusal($response->status(), $body));
        }

        return $response->successful() ? $response : null;
    }

    protected function isChallenge(string $body): bool
    {
        return $body !== ''
            && ! str_starts_with(ltrim($body), '{')
            && (bool) preg_match('/Just a moment|cf-browser-verification|cf_chl|Attention Required/i', $body);
    }

    protected function refusal(int $status, string $body): string
    {
        $reason = match (true) {
            $this->isChallenge($body) => 'alta.ge answered with a Cloudflare browser challenge, not '
                .'with data. That is not the worker token: the site is asking whoever calls it to '
                .'run JavaScript first. It needs a cf_clearance cookie for alta.ge, taken from a '
                .'browser, in ALTA_CF_CLEARANCE and forwarded by the worker — together with the '
                .'same User-Agent it was issued for, in ALTA_USER_AGENT.',

            $status === 401 => 'ALTA_ACCESS_TOKEN is missing or no longer valid — the worker checks '
                .'it on every request.',

            $status === 429 => 'Too many requests. Lower IMPORT_RATE_PER_MINUTE or run fewer workers.',

            default => 'Alta is refusing requests for now; this is usually temporary.',
        };

        return "Alta refused the request (HTTP {$status}). {$reason}";
    }
}
