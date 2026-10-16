<?php

namespace App\Services\Import\Drivers\Elite;

use Illuminate\Support\Facades\Http;

class EliteClient
{
    public function __construct(protected array $config = []) {}

    protected const BLOCKED_STATUSES = [401, 403, 429, 503];

    public function product(string $externalId): ?array
    {
        $response = Http::timeout($this->config['timeout'] ?? config('shop.import_request_timeout'))
            ->retry(2, 500, throw: false)
            ->withHeaders(['Accept' => 'application/json'])
            ->get(config('services.elite.worker_url'), [
                'type' => 'product',
                'productId' => $externalId,
                'token' => config('services.elite.token'),
            ]);

        $data = $response->json();

        if (in_array($response->status(), self::BLOCKED_STATUSES, true)) {
            throw new BlockedByElite($this->refusal($response->status()));
        }

        if (isset($data['error'])) {
            throw new BlockedByElite('Elite proxy rejected the request: '.$data['error']);
        }

        if (! $response->successful()) {
            throw new BlockedByElite('Elite proxy answered HTTP '.$response->status().'.');
        }

        return empty($data['product']) ? null : [
            'product' => $data['product'],
            'availabilityInStores' => $data['availabilityInStores'] ?? [],
        ];
    }

    public function imageUrl(string $url): string
    {
        if (! $worker = config('services.elite.worker_url')) {
            return $url;
        }

        return rtrim($worker, '/').'?'.http_build_query(array_filter([
            'type' => 'image',
            'url' => $url,
            'token' => config('services.elite.token'),
        ]));
    }

    protected function refusal(int $status): string
    {
        $reason = match ($status) {
            401, 403 => 'ELITE_TOKEN is missing or no longer valid — the proxy checks it on every '
                .'request. Replace it and run the import again.',
            429 => 'Too many requests. Lower IMPORT_RATE_PER_MINUTE or run fewer workers.',
            default => 'The proxy is unavailable for now; this is usually temporary.',
        };

        return "Elite refused the request (HTTP {$status}). {$reason}";
    }
}
