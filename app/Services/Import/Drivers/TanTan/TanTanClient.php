<?php

namespace App\Services\Import\Drivers\TanTan;

use App\Services\Import\SourceUnavailable;
use App\Support\Redact;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 | TanTan Georgia's catalogue, read from its WooCommerce REST API.
 |
 | The whole shop is under a thousand products, a hundred to a page, so it is
 | read in one sweep per language and kept for a while: an import of every
 | product would otherwise ask for the same pages once per product. The shop
 | runs WPML -- the English copy of a product is a separate product, named
 | by the Georgian one's "translations".
 */
class TanTanClient
{
    protected const PER_PAGE = 100;

    /* A page is about a megabyte and takes a few seconds to build. */
    protected const CACHE_MINUTES = 30;

    /* The cache may live in the database; one import asks for the same
       catalogue for every product, so it is held in memory once read. */
    protected array $memo = [];

    public function __construct(protected array $config = []) {}

    /**
     * Every published product in a language, keyed by its id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(string $locale, bool $fresh = false): array
    {
        $key = 'tantan:catalogue:'.$locale;

        if ($fresh) {
            Cache::forget($key);
            unset($this->memo[$key]);
        }

        return $this->memo[$key] ??= Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), function () use ($locale) {
            $products = [];
            $page = 1;

            do {
                $batch = $this->get('products', [
                    'per_page' => self::PER_PAGE,
                    'page' => $page,
                    'status' => 'publish',
                    'lang' => $this->language($locale),
                ]);

                foreach ($batch as $product) {
                    if (($product['type'] ?? 'simple') === 'simple') {
                        $products[(string) $product['id']] = $this->trim($product);
                    }
                }

                $page++;
            } while (count($batch) === self::PER_PAGE && $page <= 100);

            return $products;
        });
    }

    /** One product by id, for a product the cached sweep does not hold. */
    public function one(string $id): ?array
    {
        $product = $this->get('products/'.rawurlencode($id), [], allowMissing: true);

        return $product ? $this->trim($product) : null;
    }

    /**
     * Category id => [name, parent id], to tell the most specific category
     * of a product from the general ones it is also filed under.
     *
     * @return array<int, array{name: string, parent: int}>
     */
    public function categories(): array
    {
        return $this->memo['tantan:categories'] ??= Cache::remember('tantan:categories', now()->addHours(6), function () {
            $categories = [];
            $page = 1;

            do {
                $batch = $this->get('products/categories', ['per_page' => self::PER_PAGE, 'page' => $page]);

                foreach ($batch as $category) {
                    $categories[(int) $category['id']] = [
                        'name' => html_entity_decode((string) $category['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'parent' => (int) ($category['parent'] ?? 0),
                    ];
                }

                $page++;
            } while (count($batch) === self::PER_PAGE && $page <= 50);

            return $categories;
        });
    }

    /* Only what the driver reads is kept, so the cached catalogue stays small. */
    protected function trim(array $product): array
    {
        return [
            'id' => (string) $product['id'],
            'sku' => trim((string) ($product['sku'] ?? '')),
            'name' => html_entity_decode((string) ($product['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => (string) ($product['description'] ?? ''),
            'short_description' => (string) ($product['short_description'] ?? ''),
            'price' => (string) ($product['price'] ?? ''),
            'regular_price' => (string) ($product['regular_price'] ?? ''),
            'sale_price' => (string) ($product['sale_price'] ?? ''),
            'on_sale' => (bool) ($product['on_sale'] ?? false),
            'stock_status' => (string) ($product['stock_status'] ?? ''),
            'stock_quantity' => $product['stock_quantity'] ?? null,
            'weight' => (string) ($product['weight'] ?? ''),
            'categories' => array_map(fn (array $c) => (int) $c['id'], $product['categories'] ?? []),
            'attributes' => array_map(fn (array $a) => [
                'name' => (string) ($a['name'] ?? ''),
                'options' => array_values((array) ($a['options'] ?? [])),
            ], $product['attributes'] ?? []),
            'images' => array_values(array_filter(array_map(fn (array $i) => (string) ($i['src'] ?? ''), $product['images'] ?? []))),
            'translations' => (array) ($product['translations'] ?? []),
        ];
    }

    protected function language(string $locale): string
    {
        return $locale === 'ka' ? 'ka-ge' : $locale;
    }

    protected function get(string $path, array $query, bool $allowMissing = false): array
    {
        $key = (string) ($this->config['key'] ?? '');
        $secret = (string) ($this->config['secret'] ?? '');

        if ($key === '' || $secret === '') {
            throw new SourceUnavailable('TanTan API keys are not configured (TANTAN_KEY, TANTAN_SECRET).');
        }

        $url = rtrim((string) ($this->config['api_url'] ?? ''), '/').'/'.$path;

        try {
            $response = Http::withBasicAuth($key, $secret)
                ->timeout((int) ($this->config['timeout'] ?? 60))
                ->connectTimeout(10)
                ->retry(2, 1000, throw: false)
                ->acceptJson()
                ->get($url, $query);
        } catch (ConnectionException $e) {
            throw new SourceUnavailable('TanTan API could not be reached.', 0, $e);
        }

        if ($allowMissing && $response->status() === 404) {
            return [];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            Log::channel('import')->warning('tantan api refused', [
                'path' => $path,
                'status' => $response->status(),
                'body' => Redact::secrets(mb_substr($response->body(), 0, 300)),
            ]);

            throw new SourceUnavailable('TanTan API answered '.$response->status().' for '.$path.'.');
        }

        return $response->json();
    }

    public function forget(): void
    {
        foreach (['tantan:catalogue:ka', 'tantan:catalogue:en', 'tantan:categories'] as $key) {
            Cache::forget($key);
        }
    }
}
