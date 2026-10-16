<?php

namespace App\Services\Import\Drivers\Citrus;

use Illuminate\Support\Facades\Http;

class CitrusClient
{
    protected const BASE = 'https://citrus.ge';

    public function __construct(protected array $config = []) {}

    public function getProduct(string $url): ?array
    {
        $slug = $this->slug($url);

        return $slug ? $this->bySlug($slug) : null;
    }

    public function bySlug(string $slug): ?array
    {
        $response = Http::timeout($this->config['timeout'] ?? 30)
            ->connectTimeout(10)

            ->withOptions(['force_ip_resolve' => 'v4'])
            ->retry(2, 500, throw: false)
            ->withHeaders([
                'User-Agent' => config('services.citrus.user_agent', 'Mozilla/5.0'),
                'Accept' => 'application/json',
                'Referer' => self::BASE.'/',
            ])
            ->get($this->base().'/api/product/'.$slug);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) && ! empty($data['id']) ? $this->take($data, $slug) : null;
    }

    public function slug(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (! str_contains($url, '/')) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $parts = array_values(array_filter(explode('/', $path)));

        if (! $parts) {
            return null;
        }

        $at = array_search('product', $parts, true);

        return $at !== false
            ? ($parts[$at + 1] ?? null)
            : end($parts);
    }

    protected function take(array $data, string $slug): array
    {
        $price = (float) ($data['price'] ?? 0);
        $old = (float) ($data['old_price'] ?? 0);

        return [
            'id' => (string) $data['id'],
            'sku' => trim((string) ($data['sku'] ?? '')),
            'slug' => $slug,
            'name' => $this->clean((string) ($data['name'] ?? '')),
            'brand' => $this->clean((string) ($data['manufacturer']['name'] ?? '')) ?: null,
            'category' => $this->category($data),

            'price' => $price,

            'old_price' => $old > $price ? $old : null,

            'stock' => (int) ($data['stock'] ?? 0),
            'in_stock' => (int) ($data['stock_status'] ?? 0) > 0,

            'description' => $this->clean((string) ($data['details']['description'] ?? '')) ?: null,
            'summary' => $this->clean((string) ($data['details']['mini_description'] ?? '')) ?: null,
            'weight' => $this->weight($data['details']['weight'] ?? null),
            'warranty' => $this->clean((string) ($data['details']['warranty'] ?? '')) ?: null,

            'images' => $this->images($data),
            'attributes' => $this->attributes($data),
        ];
    }

    protected function category(array $data): ?string
    {
        $names = [];

        foreach ((array) ($data['breadcrumb'] ?? []) as $crumb) {
            $name = $this->clean((string) ($crumb['name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names ? end($names) : null;
    }

    protected function images(array $data): array
    {
        $urls = [];

        foreach ((array) ($data['large_images'] ?? []) as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                $urls[] = $url;
            }
        }

        foreach ((array) ($data['images'] ?? []) as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : $image;

            if (is_string($url) && str_starts_with($url, 'http')) {
                $urls[] = $url;
            }
        }

        if ($main = $data['image'] ?? null) {
            if (is_string($main) && str_starts_with($main, 'http')) {
                array_unshift($urls, $main);
            }
        }

        return array_values(array_unique($urls));
    }

    protected function attributes(array $data): array
    {
        $specs = [];

        foreach ((array) ($data['attributes'] ?? []) as $attribute) {
            $name = $this->clean((string) ($attribute['name'] ?? ''));
            $value = $this->clean((string) ($attribute['value'] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $specs[$name] = [
                'name' => $name,
                'value' => $value,
                'filterable' => ($attribute['type'] ?? null) === 'checkbox',
            ];
        }

        return array_values($specs);
    }

    protected function weight(mixed $raw): ?int
    {
        $text = trim((string) $raw);

        if ($text === '' || ! preg_match('/[\d.]+/', $text, $m)) {
            return null;
        }

        $number = (float) $m[0];

        return (int) round($number < 1000 ? $number * 1000 : $number) ?: null;
    }

    protected function base(): string
    {
        return rtrim((string) config('services.citrus.base_url', self::BASE), '/');
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/[ \t]+/u', ' ', html_entity_decode(strip_tags(
            preg_replace('#<br\s*/?>|</p>|</li>#i', "\n", $value),
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
