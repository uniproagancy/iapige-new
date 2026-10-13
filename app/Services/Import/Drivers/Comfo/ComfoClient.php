<?php

namespace App\Services\Import\Drivers\Comfo;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * comfo.ge product pages.
 *
 * The supplier has no feed and no API: the price list gives a code, a quantity
 * and an address, and everything a customer would read is taken from the page's
 * own structured data — the one part of a shop's markup that survives a
 * redesign.
 */
class ComfoClient
{
    /**
     * Georgian letters as the site spells them inside an address.
     *
     * Not a general transliteration: it exists only to rebuild a link that the
     * price list wrote in Georgian where the site itself uses the Latin form.
     */
    protected const KA_TO_EN = [
        'ა' => 'a', 'ბ' => 'b', 'გ' => 'g', 'დ' => 'd', 'ე' => 'e', 'ვ' => 'v', 'ზ' => 'z', 'თ' => 't',
        'ი' => 'i', 'კ' => 'k', 'ლ' => 'l', 'მ' => 'm', 'ნ' => 'n', 'ო' => 'o', 'პ' => 'p', 'ჟ' => 'zh',
        'რ' => 'r', 'ს' => 's', 'ტ' => 't', 'უ' => 'u', 'ფ' => 'p', 'ქ' => 'k', 'ღ' => 'gh', 'ყ' => 'y',
        'შ' => 'sh', 'ჩ' => 'ch', 'ც' => 'ts', 'ძ' => 'dz', 'წ' => 'w', 'ჭ' => 'ch', 'ხ' => 'kh',
        'ჯ' => 'j', 'ჰ' => 'h',
    ];

    public function __construct(protected array $config = []) {}

    /**
     * @return array{name:?string, description:?string, brand:?string, category:?string, images:array<int,string>, specs:array, in_stock:bool, price:float}|null
     */
    public function page(string $url): ?array
    {
        $html = $this->fetch($url);

        if ($html === null) {
            return null;
        }

        $json = $this->ldJson($html, 'Product');

        if (! $json) {
            return null;
        }

        // schema.org allows one offer or a list of them; the first is the product's own
        $offer = isset($json['offers'][0]) ? $json['offers'][0] : ($json['offers'] ?? []);

        return [
            'name' => $this->clean((string) ($json['name'] ?? '')) ?: null,
            'description' => $this->clean((string) ($json['description'] ?? '')) ?: null,
            'brand' => $this->clean((string) (is_array($json['brand'] ?? null) ? ($json['brand']['name'] ?? '') : ($json['brand'] ?? ''))) ?: null,
            'category' => $this->category($json, $html),
            'images' => $this->images($json),
            'specs' => $this->specs($json),
            'in_stock' => str_contains((string) ($offer['availability'] ?? ''), 'InStock'),
            'price' => (float) ($offer['price'] ?? 0),
        ];
    }

    /* ------------------------------------------------------------------ network */

    /**
     * The page, under either spelling of its address.
     *
     * A link written in Georgian is worth one more try in Latin: the price
     * list and the site do not always agree on which form a slug takes, and a
     * product that answers 404 under one answers perfectly well under the
     * other. Trying it costs one request and saves the row.
     */
    protected function fetch(string $url): ?string
    {
        $url = trim($url);
        $html = $this->get($url);

        if ($html !== null) {
            return $html;
        }

        $latin = $this->transliterate($url);

        return $latin === $url ? null : $this->get($latin);
    }

    protected function get(string $url): ?string
    {
        $response = Http::timeout($this->config['timeout'] ?? config('shop.import_request_timeout'))
            ->connectTimeout(15)
            /*
             * A refused address is an answer, not a failure.
             *
             * retry() counts any unsuccessful response as something to try
             * again, so a 404 — which is the whole signal the second spelling
             * is waiting for — cost three requests before the retry under the
             * Latin address, and another three if that one was wrong too. Six
             * requests per missing row, at a second and a half apiece. Only a
             * dropped connection or a fault on their side is worth repeating.
             */
            ->retry(2, 1500, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
            ->withHeaders([
                'User-Agent' => $this->config['user_agent'] ?? 'Mozilla/5.0',
                'Accept-Language' => 'ka',
            ])
            ->get($url);

        // a product that has gone is not a failure worth retrying the job for
        return $response->successful() ? $response->body() : null;
    }

    /**
     * The same address with its Georgian letters spelled in Latin.
     *
     * The link arrives either raw or percent-encoded depending on where the
     * price list was written, so it is decoded before the letters are looked
     * for — otherwise an encoded slug has no Georgian in it to replace and the
     * retry fetches exactly the address that just answered 404.
     */
    protected function transliterate(string $url): string
    {
        $decoded = rawurldecode($url);

        if (! preg_match('/[\x{10A0}-\x{10FF}]/u', $decoded)) {
            return $url;
        }

        return preg_replace_callback(
            '/[\x{10A0}-\x{10FF}]/u',
            fn (array $m) => self::KA_TO_EN[$m[0]] ?? '',
            $decoded,
        );
    }

    /* ------------------------------------------------------------------ reading */

    /**
     * The first structured-data node of a given type.
     *
     * The type is written either as "Product" or as its full schema.org URL,
     * and the node sits either on its own or inside an @graph, so all four
     * shapes are read here rather than guessed at by the caller.
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

    /**
     * What the product is, for the mapping queue.
     *
     * Without a name here every Comfo product would arrive uncategorised and
     * there would be nothing for an admin to map it by — the same way Kontakt
     * products once did. The product's own category field is preferred; the
     * breadcrumb is the fallback, read from its narrowest entry.
     */
    protected function category(array $json, string $html): ?string
    {
        $category = $json['category'] ?? null;
        $category = is_array($category) ? ($category['name'] ?? null) : $category;

        if (is_string($category) && ($category = $this->clean($category)) !== '') {
            // a trail written in one cell: the last step is the narrowest
            $parts = preg_split('#\s*[>/|›]\s*#u', $category);

            return trim((string) end($parts)) ?: null;
        }

        $names = [];

        foreach ($this->ldJson($html, 'BreadcrumbList')['itemListElement'] ?? [] as $item) {
            $name = $this->clean((string) ($item['name'] ?? $item['item']['name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        array_pop($names);              // the product itself
        $category = array_pop($names);  // what it sits under

        return in_array($category, ['მთავარი', 'Home', 'Главная'], true) ? null : ($category ?: null);
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

    /**
     * Specifications, where the page publishes them as structured data.
     *
     * @return array<int, array{name:string, value:string}>
     */
    protected function specs(array $json): array
    {
        $specs = [];

        foreach ((array) ($json['additionalProperty'] ?? []) as $property) {
            if (! is_array($property)) {
                continue;
            }

            $name = $this->clean((string) ($property['name'] ?? ''));
            $value = $this->clean((string) ($property['value'] ?? ''));

            if ($name === '' || $value === '' || $value === '-') {
                continue;
            }

            $specs[$name] = ['name' => trim($name, ' :：'), 'value' => $value];
        }

        return array_values($specs);
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
