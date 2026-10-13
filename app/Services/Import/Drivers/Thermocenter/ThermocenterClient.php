<?php

namespace App\Services\Import\Drivers\Thermocenter;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * thermocenter.ge product pages.
 *
 * A CS-Cart shop. Most of what we need is in the page's structured data, but
 * two things are not and have to be read off the markup itself: the price
 * before a discount, and the trail the product sits in.
 */
class ThermocenterClient
{
    public function __construct(protected array $config = []) {}

    /**
     * @return array{name:?string, sku:?string, description:?string, brand:?string, category:?string, images:array<int,string>, price:float, old_price:?float, in_stock:bool, product_id:?string}|null
     */
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

    /* ------------------------------------------------------------------ network */

    protected function get(string $url): ?string
    {
        $response = Http::timeout($this->config['timeout'] ?? config('shop.import_request_timeout'))
            ->connectTimeout(15)
            // a refused address is an answer; only a fault on their side is worth repeating
            ->retry(2, 1500, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
            ->withHeaders([
                'User-Agent' => $this->config['user_agent'] ?? 'Mozilla/5.0',
                'Accept-Language' => 'ka,en;q=0.9',
            ])
            ->get($url);

        return $response->successful() ? $response->body() : null;
    }

    /* ------------------------------------------------------------------ reading */

    /**
     * The price before the discount.
     *
     * The structured data carries only what the product sells for today, so a
     * discounted item would arrive looking like its full price was the
     * discounted one and we would strike nothing through. CS-Cart renders the
     * figure in a span named after the product, which is where it is read from.
     */
    protected function oldPrice(string $html, float $price): ?float
    {
        if (! preg_match('/<span[^>]+id="sec_list_price_\d+"[^>]*>(.*?)<\/span>/s', $html, $m)) {
            return null;
        }

        $digits = preg_replace('/[^\d.]/', '', str_replace(',', '', strip_tags($m[1])));
        $old = (float) $digits;

        /*
         * Only a figure genuinely above today's price is a previous price.
         *
         * The span is rendered whether or not there is a discount, and when
         * there is none it holds the same number — which would have every
         * product in the shop showing a strike-through of itself.
         */
        return $digits !== '' && $old > $price ? $old : null;
    }

    /**
     * What the product is, for the mapping queue.
     *
     * CS-Cart writes the trail as breadcrumb links with the name inside a
     * <bdi>, which is there to keep a Georgian name readable next to a Latin
     * one. The last link is the narrowest category and the one worth mapping;
     * the product's own name is not a link, so it is not among them.
     */
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

    /**
     * Their own id for the product.
     *
     * Kept so an admin looking at a wrong price has something to search the
     * supplier's shop by. It is not what we key on: a run is driven by the
     * price list, and this is only knowable after the page has been fetched.
     */
    protected function productId(string $html): ?string
    {
        return preg_match('/product_id=(\d+)/', $html, $m) ? $m[1] : null;
    }

    /**
     * The first structured-data node of a given type.
     *
     * The type is written either as "Product" or as its full schema.org URL,
     * and the node sits either on its own or inside an @graph.
     */
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

    /** @return array<int, string> */
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
