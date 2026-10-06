<?php

namespace App\Services\Import\Drivers\Elite;

use Illuminate\Support\Facades\Http;

/** One endpoint through the proxy worker: an id in, a product payload out. */
class EliteClient
{
    public function __construct(protected array $config = []) {}

    /** Statuses that mean "not you", never "not found". */
    protected const BLOCKED_STATUSES = [401, 403, 429, 503];

    /**
     * @throws BlockedByElite when the proxy is refusing us rather than telling
     *                        us a product does not exist
     */
    public function product(string $externalId): ?array
    {
        $response = Http::timeout($this->config['timeout'] ?? 30)
            ->retry(2, 500, throw: false)
            ->withHeaders(['Accept' => 'application/json'])
            ->get(config('services.elite.worker_url'), [
                'type' => 'product',
                'productId' => $externalId,
                'token' => config('services.elite.token'),
            ]);

        $data = $response->json();

        /*
         * A missing product and a closed door used to look identical here —
         * both returned null, quietly. So an expired worker token turned a run
         * of thirty-five thousand ids into thirty-five thousand silent
         * nothings: no product saved, no error, nothing to tell anybody the
         * token needed replacing. A product that does not exist answers 200
         * with product: null, which is the only one of these that is normal.
         */
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

    /** What to actually go and fix, per status. */
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
