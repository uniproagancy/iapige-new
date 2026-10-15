<?php

namespace App\Services\Import\Drivers\Alneo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * alneo.ge, a WooCommerce shop.
 *
 * The shop will list its whole catalogue on one page and writes each product's
 * SKU into the card markup, so the entire index — code to URL — comes from a
 * single request. Reading each product page to learn its code would be a
 * thousand requests for the same answer.
 */
class AlneoClient
{
    protected const INDEX_KEY = 'import:alneo:index';

    public function __construct(protected array $config = []) {}

    /**
     * Every product URL in the shop, keyed by SKU.
     *
     * @return array<string, string>
     */
    public function index(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::INDEX_KEY);
        }

        return Cache::remember(self::INDEX_KEY, now()->addHours(12), function () {
            // the whole catalogue on one page: the server needs a while to build it
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

    /**
     * Pairs a card's SKU with its link.
     *
     * The two sit in the same list item but in no fixed order, so each card is
     * taken whole and read from the inside — matching them across the page
     * would pair the wrong ones the moment the theme reorders anything.
     *
     * @return array<string, string>
     */
    protected function indexFrom(string $html): array
    {
        $index = [];

        // a card is a block that carries a sku attribute; cut the page on them
        $cards = preg_split('/(?=data-product_sku)/', $html);

        foreach ($cards as $card) {
            if (! preg_match('/data-product_sku=["\']([^"\']+)["\']/', $card, $sku)) {
                continue;
            }

            $code = trim($sku[1]);

            if ($code === '' || isset($index[$code])) {
                continue;
            }

            // the link may sit before or after the attribute, so both halves are searched
            if (preg_match('#href="([^"]+/product/[^"]+)"#i', $card, $link)) {
                $index[$code] = $link[1];
            }
        }

        // whatever the split missed: pair the page's links and codes in order
        if (count($index) < 10) {
            $index = $this->indexByPosition($html);
        }

        return $index;
    }

    /**
     * The fallback: codes and links in the order the page writes them.
     *
     * @return array<string, string>
     */
    protected function indexByPosition(string $html): array
    {
        preg_match_all('/data-product_sku=["\']([^"\']+)["\']/', $html, $skus);
        preg_match_all('#<a\s+[^>]*href="([^"]+/product/[^"]+)"[^>]*class="[^"]*woocommerce-LoopProduct-link#i', $html, $links);

        if (count($skus[1]) !== count($links[1])) {
            return [];
        }

        $index = [];

        foreach ($skus[1] as $i => $code) {
            $code = trim($code);

            if ($code !== '' && ! isset($index[$code])) {
                $index[$code] = $links[1][$i];
            }
        }

        return $index;
    }

    /** Every product link in the shop, for when only the links are wanted. */
    public function links(): array
    {
        return array_values($this->index());
    }

    /**
     * One product page, read from its structured data.
     *
     * @return array{sku:?string, name:?string, description:?string, category:?string, images:array, specs:array, price:float, old:float}|null
     */
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

    /* ------------------------------------------------------------------ parts */

    /** The Product node, whether it stands alone or sits inside a @graph. */
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

    /**
     * The selling price and the one it is struck through against.
     *
     * @return array{float, float}
     */
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

    /** @return array<int, string> */
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

    /**
     * What the product is made of.
     *
     * Alneo renders no attributes table at all — neither WooCommerce's
     * shop_attributes nor the theme's grid is anywhere on the page, which is
     * why nothing ever reached the admin's mapping queue for this supplier.
     * Their specifications are written into the description itself, one per
     * line:
     *
     *     <div>• სიმძლავრე: 9 000 BTU</div>
     *     <li>მაქსიმალური დატვირთვა: 50კგ</li>
     *
     * The container and the bullet vary from product to product, the shape
     * does not. The two table patterns are kept above it: they cost nothing
     * when they are absent, and a theme that grows one later should be read
     * in preference to text we had to parse.
     *
     * @return array<int, array{name:string, value:string}>
     */
    protected function specs(string $html): array
    {
        $specs = [];

        // the theme's own two-column grid comes first; it is the curated list
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

    /**
     * The description, one block element per entry.
     *
     * Split on the closing tags rather than parsed: the markup is malformed
     * — a <div> that closes with </p> is normal on these pages — and a
     * reader worth the name either repairs that into a shape the lines no
     * longer survive, or refuses it outright.
     *
     * @return array<int, string>
     */
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

    /**
     * One line of the description read as "name: value", or not at all.
     *
     * A description holds prose as well, and prose has colons in it. The
     * guards are what tells the two apart: a specification's name is short,
     * finishes no sentence of its own, and is followed by something.
     *
     * @return array{?string, ?string}
     */
    protected function asSpec(string $line): array
    {
        // the bullet is decoration, and it is a different character per product
        $line = preg_replace('/^[\s\x{2022}\x{00B7}*\-\x{2013}\x{2014}]+/u', '', $line);

        if (! str_contains($line, ':')) {
            return [null, null];
        }

        [$name, $value] = array_map('trim', explode(':', $line, 2));

        // a heading such as "მახასიათებლები:" names nothing
        if ($name === '' || $value === '') {
            return [null, null];
        }

        // a name that runs on, or closes a sentence before the colon, is prose
        if (mb_strlen($name) > 80 || preg_match('/[.!?]\s/u', $name)) {
            return [null, null];
        }

        if (mb_strlen($value) > 255) {
            return [null, null];
        }

        return [$name, $value];
    }

    /**
     * The category WooCommerce prints beneath the product.
     *
     * The price list has no column for one, so without this nothing Alneo
     * imports ever appears in the admin's category queue and every product
     * lands uncategorised with nothing to map it by.
     *
     * Read out of the product's own meta block and nowhere else. The shop's
     * navigation menu lists every category it has, each one a link of exactly
     * the same shape, so searching the page at large answers with whatever
     * sits deepest in that menu — "Android სმარტფონები" for an air
     * conditioner, which is what this did before the block was pinned down.
     *
     * Where several are listed, the one sitting deepest in their tree wins:
     * its link carries the most path segments, and it is the most specific
     * thing they have said about the product. The brand is printed the same
     * way a line below, so a link only counts as a category when it is one.
     */
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

    /* ------------------------------------------------------------------ http */

    protected function get(string $url, ?int $timeout = null, ?int $connect = null): ?string
    {
        $response = Http::timeout($timeout ?? $this->config['timeout'] ?? 60)
            ->connectTimeout($connect ?? 15)
            ->retry(2, 1500, throw: false)
            ->withOptions([
                // Windows PHP tries IPv6 first and waits out the timeout
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
