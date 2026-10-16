<?php

namespace App\Services\Import\Drivers\Allmarket;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AllmarketClient
{
    protected const CACHE_KEY = 'import:allmarket:feed';

    public function __construct(protected array $config = []) {}

    protected static ?array $memo = null;

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
