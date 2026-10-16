<?php

namespace App\Services\Import\Drivers\Thermocenter;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class ThermocenterClient
{
    public function __construct(protected array $config = []) {}

    public function page(string $url): ?array
    {
        $html = $this->get($url);

        if ($html === null) {
            return null;
        }

        $json = $this->ldJson($html, 'Product');

        if (! $json) {
            return null;
        }

        $offer = isset($json['offers'][0]) ? $json['offers'][0] : ($json['offers'] ?? []);
        $price = (float) ($offer['price'] ?? 0);

        return [
            'name' => $this->clean((string) ($json['name'] ?? '')) ?: null,
            'sku' => $this->clean((string) ($json['sku'] ?? '')) ?: null,
            'description' => $this->clean((string) ($json['description'] ?? '')) ?: null,
            'brand' => $this->clean((string) (is_array($json['brand'] ?? null) ? ($json['brand']['name'] ?? '') : ($json['brand'] ?? ''))) ?: null,
            'category' => $this->category($html),
            'images' => $this->images($json),
            'price' => $price,
            'old_price' => $this->oldPrice($html, $price),
            'in_stock' => str_contains((string) ($offer['availability'] ?? ''), 'InStock'),
            'product_id' => $this->productId($html),
        ];
    }

    protected function get(string $url): ?string
    {
        $response = Http::timeout($this->config['timeout'] ?? config('shop.import_request_timeout'))
            ->connectTimeout(15)

            ->retry(2, 1500, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
            ->withHeaders([
                'User-Agent' => $this->config['user_agent'] ?? 'Mozilla/5.0',
                'Accept-Language' => 'ka,en;q=0.9',
            ])
            ->get($url);

        return $response->successful() ? $response->body() : null;
    }

    protected function oldPrice(string $html, float $price): ?float
    {
        if (! preg_match('/<span[^>]+id="sec_list_price_\d+"[^>]*>(.*?)<\/span>/s', $html, $m)) {
            return null;
        }

        $digits = preg_replace('/[^\d.]/', '', str_replace(',', '', strip_tags($m[1])));
        $old = (float) $digits;

        return $digits !== '' && $old > $price ? $old : null;
    }

    protected function category(string $html): ?string
    {
        if (! preg_match_all('/<a[^>]+class="[^"]*ty-breadcrumbs__a[^"]*"[^>]*>\s*(?:<bdi>)?(.*?)(?:<\/bdi>)?\s*<\/a>/su', $html, $m)) {
            return null;
        }

        $names = array_values(array_filter(array_map(
            fn ($name) => $this->clean($name),
            $m[1],
        )));

        $category = array_pop($names);

        return in_array($category, ['მთავარი', 'Home', 'Главная'], true) ? null : ($category ?: null);
    }

    protected function productId(string $html): ?string
    {
        return preg_match('/product_id=(\d+)/', $html, $m) ? $m[1] : null;
    }

    protected function ldJson(string $html, string $type): ?array
    {
        if (! preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $m)) {
            return null;
        }

        foreach ($m[1] as $raw) {
            $data = json_decode(trim($raw), true);

            if (! is_array($data)) {
                continue;
            }

            foreach ([$data, ...array_values((array) ($data['@graph'] ?? []))] as $node) {
                if (is_array($node) && $this->isType($node, $type)) {
                    return $node;
                }
            }
        }

        return null;
    }

    protected function isType(array $node, string $type): bool
    {
        foreach ((array) ($node['@type'] ?? []) as $declared) {
            if (is_string($declared) && str_ends_with($declared, $type)) {
                return true;
            }
        }

        return false;
    }

    protected function images(array $json): array
    {
        $images = [];

        foreach ((array) ($json['image'] ?? []) as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : $image;

            if (is_string($url) && str_starts_with($url, 'http')) {
                $images[] = $url;
            }
        }

        return array_values(array_unique($images));
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
