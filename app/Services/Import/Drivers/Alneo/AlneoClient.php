<?php

namespace App\Services\Import\Drivers\Alneo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlneoClient
{
    protected const INDEX_KEY = 'import:alneo:index';

    public function __construct(protected array $config = []) {}

    public function index(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::INDEX_KEY);
        }

        return Cache::remember(self::INDEX_KEY, now()->addHours(12), function () {

            $html = $this->get(config('services.alneo.shop_url'), timeout: 180, connect: 60);

            if ($html === null) {
                Log::channel('import')->error('alneo listing unavailable');

                return [];
            }

            $index = $this->indexFrom($html);

            Log::channel('import')->info('alneo index built', ['products' => count($index)]);

            return $index;
        });
    }

    /*
     | Pairs each code on the listing with the page it belongs to.
     |
     | The order on the page is the whole of it: a card opens with its links
     | -- two of them, one on the picture and one on the title -- and closes
     | with the add-to-cart button, which is where the code lives. So a code's
     | own page is the last link before it, never the next one along.
     |
     | Splitting the html before each code paired every product with the one
     | after it, and the shop showed each item under its neighbour's
     | photographs and price.
     */
    protected function indexFrom(string $html): array
    {
        preg_match_all(
            '/data-product_sku=["\']([^"\']+)["\']/',
            $html, $skus, PREG_OFFSET_CAPTURE,
        );

        preg_match_all(
            '#href="([^"]+/product/[^"]+)"#i',
            $html, $links, PREG_OFFSET_CAPTURE,
        );

        if (! $skus[1] || ! $links[1]) {
            return [];
        }

        $index = [];
        $at = 0;

        foreach ($skus[1] as $sku) {
            $code = trim($sku[0]);

            /* Walk the links forward only: both lists are in page order, so
               the nearest one behind this code is found without searching
               the whole page again for every card. */
            $found = null;

            while ($at < count($links[1]) && $links[1][$at][1] < $sku[1]) {
                $found = $links[1][$at][0];
                $at++;
            }

            if ($code !== '' && $found && ! isset($index[$code])) {
                $index[$code] = $found;
            }
        }

        return $index;
    }

    public function links(): array
    {
        return array_values($this->index());
    }

    public function page(string $url): ?array
    {
        $html = $this->get($url);

        if ($html === null) {
            return null;
        }

        $json = $this->productJson($html);

        if (! $json) {
            return null;
        }

        [$price, $old] = $this->prices($json);

        return [
            'sku' => trim((string) ($json['sku'] ?? '')),
            'name' => $this->clean((string) ($json['name'] ?? '')),
            'description' => $this->description($html),
            'category' => $this->category($html),
            'images' => $this->images($json, $html),
            'specs' => $this->specs($html),
            'price' => $price,
            'old' => $old,
        ];
    }

    protected function productJson(string $html): ?array
    {
        if (! preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return null;
        }

        foreach ($m[1] as $raw) {
            $decoded = json_decode(trim($raw), true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded['@graph'] ?? [] as $node) {
                if (($node['@type'] ?? '') === 'Product') {
                    return $node;
                }
            }

            if (($decoded['@type'] ?? '') === 'Product') {
                return $decoded;
            }
        }

        return null;
    }

    protected function prices(array $json): array
    {
        $offer = $json['offers'][0] ?? $json['offers'] ?? [];
        $list = 0.0;
        $unit = 0.0;

        foreach ($offer['priceSpecification'] ?? [] as $spec) {
            $value = (float) ($spec['price'] ?? 0);

            if (str_contains((string) ($spec['priceType'] ?? ''), 'ListPrice')) {
                $list = $value;
            } else {
                $unit = $value;
            }
        }

        $main = (float) ($offer['price'] ?? 0);

        if ($list > 0 && $unit > 0 && $list > $unit) {
            return [$unit, $list];
        }

        return [$unit > 0 ? $unit : $main, 0.0];
    }

    protected function description(string $html): ?string
    {
        $parts = [];

        if (preg_match('#<div class="woocommerce-product-details__short-description">(.*?)</div>#is', $html, $m)) {
            $parts[] = trim($m[1]);
        }

        if (preg_match('#<div class="electro-description[^"]*">(.*?)</div>\s*<div class="product_meta">#is', $html, $m)) {
            $parts[] = trim($m[1]);
        }

        $text = trim(implode("\n\n", array_filter($parts)));

        if ($text === '') {
            return null;
        }

        foreach ((array) ($this->config['replace'] ?? []) as $from => $to) {
            $text = preg_replace('/'.preg_quote($from, '/').'/i', $to, $text);
        }

        return $this->clean($text);
    }

    protected function images(array $json, string $html): array
    {
        $images = [];

        foreach ((array) ($json['image'] ?? []) as $image) {
            $url = is_string($image) ? $image : ($image['url'] ?? null);

            if ($url) {
                $images[] = $url;
            }
        }

        foreach ([
            '#<(?:div|figure)[^>]*class="[^"]*woocommerce-product-gallery__image[^"]*"[^>]*>\s*<a[^>]+href="([^"]+)"#is',
            '#data-large_image=["\']([^"\']+)["\']#i',
        ] as $pattern) {
            if (preg_match_all($pattern, $html, $m)) {
                $images = array_merge($images, $m[1]);
            }
        }

        return array_values(array_filter(
            array_unique($images),
            fn ($url) => filter_var($url, FILTER_VALIDATE_URL)
                && preg_match('/\.(jpe?g|png|webp)(\?|$|#)/i', $url),
        ));
    }

    protected function specs(string $html): array
    {
        $specs = [];

        if (preg_match_all(
            '#<div class="mb-3\.5 grid grid-cols-2[^"]*">\s*<span[^>]*>([^<]+)</span>\s*<span[^>]*>([^<]+)</span>\s*</div>#is',
            $html, $rows, PREG_SET_ORDER
        )) {
            foreach ($rows as $row) {
                $this->addSpec($specs, $row[1], $row[2]);
            }
        }

        if (preg_match('#<table[^>]*class="[^"]*shop_attributes[^"]*"[^>]*>(.*?)</table>#is', $html, $table)
            && preg_match_all('#<tr[^>]*>\s*<th[^>]*>(.*?)</th>\s*<td[^>]*>(.*?)</td>\s*</tr>#is', $table[1], $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $this->addSpec($specs, $row[1], $row[2]);
            }
        }

        foreach ($this->descriptionLines($html) as $line) {
            [$name, $value] = $this->asSpec($line);

            if ($name !== null) {
                $this->addSpec($specs, $name, $value);
            }
        }

        return array_values($specs);
    }

    protected function descriptionLines(string $html): array
    {
        if (! preg_match('#<div class="electro-description[^"]*">(.*?)</div>\s*<div class="product_meta">#is', $html, $m)) {
            return [];
        }

        $lines = [];

        foreach (preg_split('#</(?:div|li|p|h[1-6])>|<br\s*/?>#i', $m[1]) as $chunk) {
            $text = $this->clean(strip_tags($chunk));

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    protected function asSpec(string $line): array
    {

        $line = preg_replace('/^[\s\x{2022}\x{00B7}*\-\x{2013}\x{2014}]+/u', '', $line);

        if (! str_contains($line, ':')) {
            return [null, null];
        }

        [$name, $value] = array_map('trim', explode(':', $line, 2));

        if ($name === '' || $value === '') {
            return [null, null];
        }

        if (mb_strlen($name) > 80 || preg_match('/[.!?]\s/u', $name)) {
            return [null, null];
        }

        if (mb_strlen($value) > 255) {
            return [null, null];
        }

        return [$name, $value];
    }

    protected function category(string $html): ?string
    {
        if (! preg_match('#<div class="product_meta">(.*?)</div>#is', $html, $meta)) {
            return null;
        }

        if (! preg_match_all(
            '#<a[^>]+href="[^"]*/product-category/([^"]*)"[^>]*>(.*?)</a>#is',
            $meta[1], $links, PREG_SET_ORDER
        )) {
            return null;
        }

        $best = null;
        $depth = -1;

        foreach ($links as $link) {
            $name = $this->clean(strip_tags($link[2]));
            $segments = count(array_filter(explode('/', trim($link[1], '/'))));

            if ($name !== '' && $segments > $depth) {
                $best = $name;
                $depth = $segments;
            }
        }

        return $best;
    }

    protected function addSpec(array &$specs, string $name, string $value): void
    {
        $name = trim($this->clean(strip_tags($name)), ' :：');
        $value = $this->clean(strip_tags($value));

        if ($name !== '' && $value !== '' && $value !== '-' && ! isset($specs[$name])) {
            $specs[$name] = ['name' => $name, 'value' => $value];
        }
    }

    protected function get(string $url, ?int $timeout = null, ?int $connect = null): ?string
    {
        $response = Http::timeout($timeout ?? $this->config['timeout'] ?? 60)
            ->connectTimeout($connect ?? 15)
            ->retry(2, 1500, throw: false)
            ->withOptions([

                'force_ip_resolve' => 'v4',
            ])
            ->withHeaders([
                'User-Agent' => config('services.alneo.user_agent', 'Mozilla/5.0'),
                'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
            ])
            ->get($url);

        return $response->successful() ? $response->body() : null;
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public function forget(): void
    {
        Cache::forget(self::INDEX_KEY);
    }
}
