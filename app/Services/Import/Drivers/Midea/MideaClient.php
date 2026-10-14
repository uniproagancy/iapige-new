<?php

namespace App\Services\Import\Drivers\Midea;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;

/**
 * Midea renders its catalogue on the server, so the listing pages carry
 * everything a card needs — title, model, prices, image and a short spec list.
 * That makes a plain crawl both simpler and more complete than the search
 * endpoint, which returns only what the autocomplete shows.
 */
class MideaClient
{
    /** Listing card: /ka/product/<slug>/<id> */
    protected const PRODUCT_URL = '#/product/[^/]+/(\d+)$#';

    public function __construct(protected array $config = []) {}

    /**
     * The site's own search, which answers with the whole product.
     *
     * The shop's header search posts a keyword to /ka/search and gets JSON
     * back: the title, both prices, the main photograph and the gallery, the
     * model number, the stock flag and the weight. Everything an import needs,
     * in one request.
     *
     * Which is worth saying plainly, because the long way round was: crawl
     * seven category listings page by page, index every card by model code,
     * fall back to the sitemap when the index misses, and fetch the product
     * page when the card had no picture. Hundreds of requests, a six hour
     * cache, and still only the models those seven listings happened to cover
     * — everything else arrived with no photograph and a name assembled out of
     * the spreadsheet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $keyword): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        try {
            $response = Http::timeout($this->config['timeout'] ?? config('shop.import_request_timeout'))
                ->connectTimeout(15)
                ->asForm()
                ->withHeaders([
                    'User-Agent' => config('services.midea.user_agent', 'Mozilla/5.0'),
                    // the endpoint only answers the page's own script
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => rtrim((string) config('services.midea.base_url'), '/').'/ka/',
                ])
                ->post($this->absolute('/ka/search'), ['keyword' => $keyword]);
        } catch (\Throwable $e) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();

        // status 0 means it did not understand the request, not that there is nothing
        return (int) ($body['status'] ?? 0) === 1 && is_array($body['products'] ?? null)
            ? $body['products']
            : [];
    }

    /**
     * One product from the search, as a card the driver can use.
     *
     * The search is a search: ask it for a model and it answers with
     * everything whose text mentions it. So the answer is only accepted when
     * its own model_number matches what was asked for — otherwise a product
     * arrives wearing another product's photographs, which is worse than
     * arriving with none.
     *
     * @return array<string, mixed>|null
     */
    public function card(string $model): ?array
    {
        $key = $this->modelKey($model);

        if ($key === '') {
            return null;
        }

        foreach ($this->attempts($model) as $keyword) {
            if ($card = $this->cardFrom($keyword, $key)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * The keywords worth asking for, in order.
     *
     * The search matches the whole string, and any extra word in it kills the
     * result outright — "MDRB489FGE02OE" finds the fridge, "Midea
     * MDRB489FGE02OE" and "MDRB489FGE02OE INOX" both find nothing. Which is
     * what a spreadsheet hands us: a model with the brand in front of it, or a
     * colour on the end, written differently in every row.
     *
     * So the whole string first — it is right often enough and costs one
     * request — and then its words, longest first, because the model code is
     * the longest thing in a cell like "Midea MDRB489FGE02OE INOX". Short
     * words are not worth asking about: "INV" matches half the catalogue and
     * the brand matches all of it.
     *
     * @return array<int, string>
     */
    protected function attempts(string $model): array
    {
        $model = trim($model);
        $words = preg_split('/\s+/u', $model, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) < 2) {
            return [$model];
        }

        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $brand = mb_strtolower((string) ($this->config['brand'] ?? 'midea'));

        $words = array_values(array_filter(
            $words,
            fn ($word) => mb_strlen($word) >= self::MIN_KEYWORD_LENGTH
                && mb_strtolower($word) !== $brand,
        ));

        return array_merge([$model], $words);
    }

    /**
     * The first search result that really is this model.
     *
     * @return array<string, mixed>|null
     */
    protected function cardFrom(string $keyword, string $key): ?array
    {
        foreach ($this->search($keyword) as $product) {
            if (! $this->isSameModel($key, (string) ($product['model_number'] ?? ''))) {
                continue;
            }

            return [
                'id' => $product['id'] ?? null,
                'url' => $this->normalise((string) ($product['link'] ?? '')),
                'name' => $this->clean((string) ($product['title'] ?? '')),
                'description' => $this->clean((string) ($product['text'] ?? $product['short_text'] ?? '')) ?: null,
                'category' => $this->clean((string) ($product['description_title'] ?? '')) ?: null,
                'images' => $this->searchImages($product),
                'in_stock' => (string) ($product['in_stock'] ?? '1') === '1',
                'weight' => (int) ($product['weight'] ?? 0) ?: null,
                // the search carries the whole product, so its page adds nothing
                'needs_page' => false,
            ];
        }

        return null;
    }

    /**
     * Whether the search result is the model that was asked for.
     *
     * The site writes a model number as the bare code on one product and with
     * the brand in front of it on the next — "FS40-21MR" against
     * "Midea MDRB489FGE02OE" — so neither side can be required to equal the
     * other, only to contain it.
     */
    protected function isSameModel(string $key, string $found): bool
    {
        $found = $this->modelKey($found);

        if ($found === '' || $key === '') {
            return false;
        }

        return str_contains($found, $key) || str_contains($key, $found);
    }

    /**
     * The main photograph and the gallery, as addresses.
     *
     * Both arrive as bare filenames under the site's product media path, and
     * the gallery arrives as JSON inside the JSON.
     *
     * @return array<int, string>
     */
    protected function searchImages(array $product): array
    {
        $path = rtrim(config('services.midea.image_path', '/uploads/products/'), '/').'/';
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
            fn (string $file) => $this->absolute($path.ltrim($file, '/')),
            $files,
        )));
    }

    /** The site writes its own links with a doubled slash after the locale. */
    protected function normalise(string $url): string
    {
        return preg_replace('#(?<!:)//+#', '/', trim($url));
    }

    /** Below this a word matches half the catalogue and is not worth a request. */
    protected const MIN_KEYWORD_LENGTH = 5;

    protected function modelKey(string $model): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $model));
    }

    /* ------------------------------------------------------------------ listing */

    /**
     * Every product card on one listing page.
     *
     * @return array<int, array> keyed by the supplier's product id
     */
    public function listing(string $url): array
    {
        $html = $this->get($url);

        if ($html === null) {
            return [];
        }

        $xpath = $this->xpath($html);
        $cards = [];

        foreach ($xpath->query('//a[contains(@class,"products-item")]') ?: [] as $node) {
            $href = $node->getAttribute('href');

            if (! preg_match(self::PRODUCT_URL, $href, $m)) {
                continue;   // the search prototype card carries no id
            }

            $id = $m[1];

            $cards[$id] = [
                'id' => $id,
                'url' => $this->absolute($href),
                'name' => $this->text($xpath, './/*[contains(@class,"products-item-title-text")]', $node),
                'model' => $this->text($xpath, './/*[contains(@class,"products-item-model-value")]', $node),
                'price' => $this->money($this->text($xpath, './/*[contains(@class,"products-item-price-value")]', $node)),
                'old_price' => $this->money($this->text($xpath, './/*[contains(@class,"products-item-price-old")]', $node)),
                'image' => $this->backgroundImage($xpath, $node),
                // the card's summary is a <br>-separated list of "label: value"
                'specs' => $this->specsFromSummary($xpath, $node),
            ];
        }

        return $cards;
    }

    /** True while a listing page still returns products. */
    public function hasProducts(string $url): bool
    {
        return $this->listing($url) !== [];
    }

    /* ------------------------------------------------------------------ product page */

    /**
     * What the product page adds to a card: the full gallery, the description
     * and any spec table the card's summary did not cover.
     */
    public function page(string $url): array
    {
        $html = $this->get($url);

        if ($html === null) {
            return [];
        }

        $xpath = $this->xpath($html);

        return [
            'description' => $this->text($xpath, '//*[contains(@class,"product-description")] | //*[contains(@class,"description-text")]'),
            'specs' => $this->specsFromPage($xpath),
            'images' => $this->galleryImages($xpath),
        ];
    }

    /**
     * The summary under a card reads "მაქსიმალური ჩატვირთვა: 6 კგ<br>…",
     * which is a specification list in everything but markup.
     *
     * @return array<int, array{name:string, value:string}>
     */
    protected function specsFromSummary(DOMXPath $xpath, $context = null): array
    {
        $node = $xpath->query('.//*[contains(@class,"products-item-description-text")]', $context)?->item(0);

        if (! $node) {
            return [];
        }

        // <br> is the only separator, so the inner HTML is split on it
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $child->ownerDocument->saveHTML($child);
        }

        return $this->pairs(preg_split('/<br\s*\/?>/i', $html) ?: []);
    }

    /** A spec table on the product page, read as label/value rows. */
    protected function specsFromPage(DOMXPath $xpath): array
    {
        $specs = [];

        foreach ((array) config('services.midea.spec_xpath', [
            '//table//tr[td]',
            '//*[contains(@class,"specification")]//li',
            '//*[contains(@class,"product-params")]//div[count(*)=2]',
        ]) as $query) {
            foreach ($xpath->query($query) ?: [] as $row) {
                $cells = [];

                foreach ($row->childNodes as $cell) {
                    $text = $this->clean($cell->textContent ?? '');

                    if ($text !== '') {
                        $cells[] = $text;
                    }
                }

                if (count($cells) >= 2) {
                    $specs[$cells[0]] = ['name' => trim($cells[0], ' :：'), 'value' => $cells[1]];
                }
            }

            if ($specs) {
                break;
            }
        }

        return array_values($specs);
    }

    /** @param array<int, string> $lines */
    protected function pairs(array $lines): array
    {
        $specs = [];

        foreach ($lines as $line) {
            $text = $this->clean(strip_tags($line));

            if ($text === '' || ! str_contains($text, ':')) {
                continue;   // "ანტიბაქტერიული რეცხვა" is a feature, not a spec
            }

            [$name, $value] = array_map('trim', explode(':', $text, 2));

            if ($name !== '' && $value !== '' && $value !== '-') {
                $specs[$name] = ['name' => $name, 'value' => $value];
            }
        }

        return array_values($specs);
    }

    /* ------------------------------------------------------------------ images */

    protected function backgroundImage(DOMXPath $xpath, $context): ?string
    {
        $node = $xpath->query('.//*[contains(@class,"products-item-image")]', $context)?->item(0);

        if (! $node) {
            return null;
        }

        return preg_match('/url\((.*?)\)/', $node->getAttribute('style'), $m)
            ? $this->absolute(trim($m[1], "'\" "))
            : null;
    }

    /** @return array<int, string> */
    protected function galleryImages(DOMXPath $xpath): array
    {
        $urls = [];
        $path = config('services.midea.image_path', '/uploads/products/');

        foreach ($xpath->query('//img[@src] | //*[contains(@style,"background-image")]') ?: [] as $node) {
            $src = $node->getAttribute('src');

            if (! $src && preg_match('/url\((.*?)\)/', $node->getAttribute('style'), $m)) {
                $src = trim($m[1], "'\" ");
            }

            // only files under the product media path are product photos
            if ($src && str_contains($src, $path)) {
                $urls[] = $this->absolute($src);
            }
        }

        return array_values(array_unique($urls));
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * Every product address the site publishes, from its own sitemap.
     *
     * The crawl walks the listing pages named in the supplier's config, and
     * those cover a fraction of what the price list holds: seven narrow
     * sub-pages against twenty-one categories, which indexed 54 cards for 368
     * models. Products outside them were imported with no picture, because
     * nothing had ever looked for them.
     *
     * The sitemap is one request instead of two hundred pages, and it lists
     * products the listings never reach.
     *
     * @return array<int, string>
     */
    public function sitemapProducts(string $locale = 'ka'): array
    {
        $index = $this->get($this->absolute('/sitemap.xml'));

        if ($index === null) {
            return [];
        }

        $urls = [];

        foreach ($this->locations($index) as $map) {
            // one sitemap per language; the other is the same products again
            if (! str_contains($map, "_{$locale}.")) {
                continue;
            }

            $body = $this->get($map);

            if ($body === null) {
                continue;
            }

            foreach ($this->locations($body) as $url) {
                if (str_contains($url, "/{$locale}/product/")) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /** @return array<int, string> */
    protected function locations(string $xml): array
    {
        preg_match_all('#<loc>\s*(.*?)\s*</loc>#i', $xml, $m);

        return array_map('html_entity_decode', $m[1] ?? []);
    }

    protected function get(string $url): ?string
    {
        $response = Http::timeout($this->config['timeout'] ?? config('shop.import_request_timeout'))
            ->retry(2, 800, throw: false)
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml',
                'User-Agent' => config('services.midea.user_agent', 'Mozilla/5.0'),
                'Referer' => config('services.midea.base_url'),
            ])
            ->get($url);

        return $response->successful() ? $response->body() : null;
    }

    protected function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML('<?xml encoding="UTF-8">'.$html);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    protected function text(DOMXPath $xpath, string $query, $context = null): ?string
    {
        $node = $xpath->query($query, $context)?->item(0);

        return $node ? ($this->clean($node->textContent) ?: null) : null;
    }

    /** "649 GEL" → 649.0 */
    protected function money(?string $text): ?float
    {
        if (! $text) {
            return null;
        }

        $digits = preg_replace('/[^\d.]/', '', str_replace(',', '', $text));

        return $digits === '' ? null : (float) $digits;
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public function absolute(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $url = str_starts_with($url, 'http')
            ? $url
            : rtrim((string) config('services.midea.base_url'), '/').'/'.ltrim($url, '/');

        /*
         * The site writes some of its own addresses with a doubled slash —
         * https://www.midea.ge//uploads/products/x.jpg — and it serves them
         * either way. The downloader names a stored file after a hash of the
         * address, so the two spellings of one picture would be downloaded and
         * kept twice.
         */
        return (string) preg_replace('#(?<!:)//+#', '/', $url);
    }
}
