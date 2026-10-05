<?php

namespace App\Services\Import\Drivers\Kontakt;

use Illuminate\Support\Facades\Http;

/**
 * kontakt.ge product pages.
 *
 * The site publishes each product as structured data and renders its
 * specifications in a pair of divs, so both are read rather than scraped from
 * the surrounding layout.
 */
class KontaktClient
{
    public function __construct(protected array $config = []) {}

    /**
     * @return array{name:?string, description:?string, brand:?string, image:?string, specs:array, in_stock:bool, price:float}|null
     */
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
            'name' => $this->title((string) ($json['name'] ?? '')),
            'description' => $this->clean((string) ($json['description'] ?? '')) ?: null,
            'brand' => $json['brand']['name'] ?? null,
            'images' => $this->images($json, $html),
            'specs' => $this->specs($html),
            'in_stock' => str_contains((string) ($json['offers']['availability'] ?? ''), 'InStock'),
            'price' => (float) ($json['offers']['price'] ?? 0),
        ];
    }

    protected function images(array $json, string $html): array
    {
        $images = [];

        foreach ($this->galleryItems($html) as $item) {
            // "full" is the original; "img" is what the gallery displays
            $url = $item['full'] ?? $item['img'] ?? null;

            if (is_string($url) && $url !== '') {
                $images[] = $url;
            }
        }

        // the structured data names the main picture, for pages with no gallery
        if (! $images) {
            foreach ((array) ($json['image'] ?? []) as $image) {
                $url = is_string($image) ? $image : ($image['url'] ?? null);

                if ($url) {
                    $images[] = $url;
                }
            }
        }

        /*
         * Every link in the gallery is a cached thumbnail. The original sits at
         * the same path without the cache segment and is about twice the size,
         * which is worth it: a photograph is the one thing a customer judges
         * before buying.
         */
        $images = array_map(
            fn ($url) => preg_replace('#/cache/[0-9a-f]{32}/#i', '/', $url),
            $images,
        );

        return array_values(array_unique(array_filter($images)));
    }

    /**
     * The gallery's JSON array.
     *
     * Its entries carry nested objects — a srcset per picture — so the array is
     * found by counting brackets rather than by a lazy pattern, which would
     * stop at the first bracket the nesting closes and return one picture.
     *
     * @return array<int, array>
     */
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

    /** The site serves the same product under /ka/, /en/ and /ru/; one is enough. */
    protected function normalise(string $url): string
    {
        return preg_replace('#^(https?://[^/]+)/(?:en|ka|ru)(/|$)#i', '$1/', trim($url));
    }

    protected function productJson(string $html): ?array
    {
        if (! preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $m)) {
            return null;
        }

        foreach ($m[1] as $raw) {
            $data = json_decode(trim($raw), true);

            if (! is_array($data)) {
                continue;
            }

            if (($data['@type'] ?? '') === 'Product') {
                return $data;
            }

            foreach ($data['@graph'] ?? [] as $node) {
                if (($node['@type'] ?? '') === 'Product') {
                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * The specification list, written as paired divs.
     *
     * @return array<int, array{name:string, value:string}>
     */
    protected function specs(string $html): array
    {
        $specs = [];

        if (! preg_match_all('#<div class="har__title">(.*?)</div>\s*<div class="har__znach">(.*?)</div>#s', $html, $rows, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($rows as $row) {
            $name = $this->clean(strip_tags($row[1]));
            $value = $this->clean(strip_tags($row[2]));

            // the site writes "unavailable" where it has no answer
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
        $response = Http::timeout($this->config['timeout'] ?? 30)
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
