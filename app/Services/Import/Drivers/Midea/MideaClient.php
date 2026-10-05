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

    protected function get(string $url): ?string
    {
        $response = Http::timeout($this->config['timeout'] ?? 30)
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

        return str_starts_with($url, 'http')
            ? $url
            : rtrim((string) config('services.midea.base_url'), '/').'/'.ltrim($url, '/');
    }
}
