<?php

namespace App\Services\Import\Drivers\Midea;

use Illuminate\Support\Facades\Http;

class MideaClient
{
    public function __construct(protected array $config = []) {}

    public function searchByName(string $name): ?array
    {
        $wanted = $this->key($name);

        if ($wanted === '') {
            return null;
        }

        foreach ($this->keywords($name) as $keyword) {
            foreach ($this->search($keyword) as $product) {
                if ($this->matches($wanted, (string) ($product['model_number'] ?? ''))) {
                    return $this->take($product);
                }
            }
        }

        return null;
    }

    protected function keywords(string $name): array
    {
        $name = trim($name);
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) < 2) {
            return [$name];
        }

        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $brand = mb_strtolower((string) ($this->config['brand'] ?? 'midea'));

        $words = array_filter($words, fn ($w) => mb_strlen($w) >= 5 && mb_strtolower($w) !== $brand);

        return array_merge([$name], array_values($words));
    }

    public function search(string $keyword): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        $base = $this->base();

        $response = Http::timeout($this->config['timeout'] ?? 30)
            ->connectTimeout(10)

            ->withOptions(['force_ip_resolve' => 'v4'])

            ->retry(2, 500, throw: false)
            ->asForm()
            ->withHeaders([
                'User-Agent' => config('services.midea.user_agent', 'Mozilla/5.0'),
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => $base.'/ka/',
            ])
            ->post($base.'/ka/search', ['keyword' => $keyword]);

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();

        return (int) ($body['status'] ?? 0) === 1 && is_array($body['products'] ?? null)
            ? $body['products']
            : [];
    }

    protected function take(array $product): array
    {
        $price = (float) ($product['price'] ?? 0);
        $sale = (float) ($product['sale_price'] ?? 0);

        return [
            'sku' => trim((string) ($product['model_number'] ?? $product['id'] ?? '')),
            'name' => $this->clean((string) ($product['title'] ?? '')),
            'brand' => $this->config['brand'] ?? 'Midea',
            'price' => $price,

            'sale_price' => $sale > 0 && $sale < $price ? $sale : null,
            'description' => $this->clean((string) ($product['text'] ?? $product['short_text'] ?? '')) ?: null,
            'images' => $this->images($product),
            'in_stock' => (string) ($product['in_stock'] ?? '1') === '1',
            'weight' => (int) ($product['weight'] ?? 0) ?: null,
        ];
    }

    protected function matches(string $wanted, string $found): bool
    {
        $found = $this->key($found);

        return $found !== '' && (str_contains($found, $wanted) || str_contains($wanted, $found));
    }

    protected function images(array $product): array
    {
        $path = '/'.trim((string) config('services.midea.image_path', '/uploads/products/'), '/').'/';
        $files = [];

        if ($main = trim((string) ($product['image'] ?? ''))) {
            $files[] = $main;
        }

        $gallery = $product['gallery'] ?? null;
        $gallery = is_string($gallery) ? json_decode($gallery, true) : $gallery;

        foreach ((array) $gallery as $file) {
            if (is_string($file) && trim($file) !== '') {
                $files[] = trim($file);
            }
        }

        return array_values(array_unique(array_map(
            fn (string $file) => $this->base().$path.ltrim($file, '/'),
            $files,
        )));
    }

    protected function base(): string
    {
        return rtrim((string) config('services.midea.base_url', 'https://www.midea.ge'), '/');
    }

    protected function key(string $model): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $model));
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
            preg_replace('#<br\s*/?>#i', ' ', $value),
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
