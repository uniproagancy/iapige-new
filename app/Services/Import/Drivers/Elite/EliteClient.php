<?php

namespace App\Services\Import\Drivers\Elite;

use Illuminate\Support\Facades\Http;

/** One endpoint through the proxy worker: an id in, a product payload out. */
class EliteClient
{
    public function __construct(protected array $config = [])
    {
    }

    public function product(string $externalId): ?array
    {
        $response = Http::timeout($this->config['timeout'] ?? 30)
            ->retry(2, 500, throw: false)
            ->withHeaders(['Accept' => 'application/json'])
            ->get(config('services.elite.worker_url'), [
                'type'      => 'product',
                'productId' => $externalId,
                'token'     => config('services.elite.token'),
            ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return empty($data['product']) ? null : [
            'product'              => $data['product'],
            'availabilityInStores' => $data['availabilityInStores'] ?? [],
        ];
    }
}
