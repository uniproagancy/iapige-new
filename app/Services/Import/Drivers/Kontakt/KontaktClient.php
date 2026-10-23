<?php

namespace App\Services\Import\Drivers\Kontakt;

use Illuminate\Support\Facades\Http;

class KontaktClient
{
    public function __construct(protected array $config = []) {}

    public function page(string $url): ?array
    {
        $html = $this->get($this->normalise($url));

        if ($html === null) {
            return null;
        }

        $json = $this->productJson($html);

        if (! $json) {
            return null;
        }

        return [
            /* Kontakt's own article number, which the shop would rather show
               than a code built out of the price list. */
            'sku' => trim((string) ($json['sku'] ?? '')) ?: null,

            'name' => $this->title((string) ($json['name'] ?? '')),
            'description' => $this->clean((string) ($json['description'] ?? '')) ?: null,
            'brand' => $json['brand']['name'] ?? null,
            'images' => $this->images($json, $html),
            'category' => $this->category($html),
            'specs' => $this->specs($html),
            'in_stock' => str_contains((string) ($json['offers']['availability'] ?? ''), 'InStock'),
            'price' => (float) ($json['offers']['price'] ?? 0),
        ];
    }

    protected function images(array $json, string $html): array
    {
        $images = [];

        foreach ($this->galleryItems($html) as $item) {

            $url = $item['full'] ?? $item['img'] ?? null;

            if (is_string($url) && $url !== '') {
                $images[] = $url;
            }
        }

        if (! $images) {
            foreach ((array) ($json['image'] ?? []) as $image) {
                $url = is_string($image) ? $image : ($image['url'] ?? null);

                if ($url) {
                    $images[] = $url;
                }
            }
        }

        $images = array_map(
            fn ($url) => preg_replace('#/cache/[0-9a-f]{32}/#i', '/', $url),
            $images,
        );

        return array_values(array_unique(array_filter($images)));
    }

    protected function galleryItems(string $html): array
    {
        $start = strpos($html, '"mage/gallery/gallery"');

        if ($start === false) {
            return [];
        }

        $open = strpos($html, '[', $start);

        if ($open === false) {
            return [];
        }

        $depth = 0;

        for ($i = $open, $length = strlen($html); $i < $length; $i++) {
            if ($html[$i] === '[') {
                $depth++;
            } elseif ($html[$i] === ']') {
                $depth--;

                if ($depth === 0) {
                    $items = json_decode(substr($html, $open, $i - $open + 1), true);

                    return is_array($items) ? $items : [];
                }
            }
        }

        return [];
    }

    protected function normalise(string $url): string
    {
        return preg_replace('#^(https?://[^/]+)/(?:en|ka|ru)(/|$)#i', '$1/', trim($url));
    }

    protected function productJson(string $html): ?array
    {
        return $this->ldJson($html, 'Product');
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

            if (($data['@type'] ?? '') === $type) {
                return $data;
            }

            foreach ($data['@graph'] ?? [] as $node) {
                if (($node['@type'] ?? '') === $type) {
                    return $node;
                }
            }
        }

        return null;
    }

    protected function category(string $html): ?string
    {
        $names = [];

        foreach ($this->ldJson($html, 'BreadcrumbList')['itemListElement'] ?? [] as $item) {
            $name = $this->clean((string) ($item['name'] ?? $item['item']['name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        array_pop($names);
        $category = array_pop($names);

        return in_array($category, ['მთავარი', 'Home', 'Главная'], true)
            ? null
            : ($category ?: null);
    }

    protected function specs(string $html): array
    {
        $specs = [];

        if (! preg_match_all('#<div class="har__title">(.*?)</div>\s*<div class="har__znach">(.*?)</div>#s', $html, $rows, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($rows as $row) {
            $name = $this->clean(strip_tags($row[1]));
            $value = $this->clean(strip_tags($row[2]));

            if ($name === '' || $value === '' || $value === '-'
                || mb_strpos($value, 'მიუწვდომელია') !== false) {
                continue;
            }

            $specs[$name] = ['name' => trim($name, ' :：'), 'value' => $value];
        }

        return array_values($specs);
    }

    protected function title(string $title): string
    {
        return trim(preg_replace('/\s*\|\s*Kontakt\.ge\s*$/i', '', $this->clean($title)));
    }

    protected function get(string $url): ?string
    {
        $response = Http::timeout($this->config['timeout'] ?? config('shop.import_request_timeout'))
            ->connectTimeout(15)
            ->retry(2, 1500, throw: false)
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->withHeaders([
                'User-Agent' => config('services.kontakt.user_agent', 'Mozilla/5.0'),
                'Accept-Language' => 'ka',
            ])
            ->get($url);

        return $response->successful() ? $response->body() : null;
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
