<?php

namespace App\Services\Import\Drivers\Allmarket;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Allmarket's B2B feed.
 *
 * One POST returns the whole catalogue, so the feed is fetched once per run and
 * held in the cache: the per-product jobs read from it instead of asking again,
 * and the supplier sees one request rather than thousands.
 */
class AllmarketClient
{
    protected const CACHE_KEY = 'import:allmarket:feed';

    public function __construct(protected array $config = []) {}

    /**
     * The whole feed, held for the life of this worker process.
     *
     * product() answers from the feed, and one import job per product meant one
     * read of the entire catalogue out of the cache table — plus unserialising
     * it — for every single product. Thousands of products turned a few
     * megabytes of feed into gigabytes of pointless work.
     *
     * @var array<string, array>|null
     */
    protected static ?array $memo = null;

    /**
     * Every product the supplier offers, keyed by product code.
     *
     * @return array<string, array>
     */
    public function feed(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
            self::$memo = null;
        }

        if (self::$memo !== null) {
            return self::$memo;
        }

        return self::$memo = Cache::remember(self::CACHE_KEY, now()->addHours(6), function () {
            $response = Http::withHeaders([
                'AppSecret' => config('services.allmarket.app_secret'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout($this->config['timeout'] ?? 60)
                ->retry(2, 2000, throw: false)
                ->post(config('services.allmarket.url'));

            if (! $response->successful()) {
                Log::channel('import')->error('allmarket feed unavailable', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                ]);

                throw new RuntimeException('Allmarket did not answer.');
            }

            $data = $response->json() ?? [];

            // the endpoint answers 200 with succeeded=false when it refuses
            if (! ($data['succeeded'] ?? false)) {
                throw new RuntimeException('Allmarket refused: '.($data['message'] ?? 'no reason given'));
            }

            $products = $data['data']['products'] ?? [];
            $keyed = [];

            foreach ($products as $product) {
                $code = trim((string) ($product['productCode'] ?? ''));

                if ($code !== '') {
                    $keyed[$code] = $product;
                }
            }

            Log::channel('import')->info('allmarket feed loaded', ['products' => count($keyed)]);

            return $keyed;
        });
    }

    public function product(string $code): ?array
    {
        return $this->feed()[$code] ?? null;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::$memo = null;
    }
}
