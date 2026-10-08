<?php

namespace App\Services\Import\Drivers\Ingco;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;

/**
 * ingco.ge, read through its own search.
 *
 * The supplier's spreadsheet lists model codes and nothing else, so each model
 * is looked up in the site's autocomplete to find its page, and the page is
 * read for everything the price list does not carry.
 */
class IngcoClient
{
    protected const BASE = 'https://ingco.ge';

    public function __construct(protected array $config = []) {}

    /** The product page for a model code, or null when the site has none. */
    public function findUrl(string $model): ?string
    {
        $response = $this->get(self::BASE.'/ka/catalog/searchtermautocomplete', [
            'term' => $model,
            'pageIndex' => 0,
        ], ['X-Requested-With' => 'XMLHttpRequest']);

        if ($response === null) {
            return null;
        }

        // the endpoint sometimes answers with a JSON-encoded string of HTML
        $html = json_decode($response) ?: $response;

        return preg_match('#href="(/ka/[^"]+)"#', (string) $html, $m)
            ? self::BASE.$m[1]
            : null;
    }

    /**
     * Everything the product page carries.
     *
     * @return array{name:?string, description:?string, images:array, specs:array, in_stock:bool}|null
     */
    public function page(string $url): ?array
    {
        $html = $this->get($url);

        if ($html === null) {
            return null;
        }

        // the page publishes itself as structured data, which beats scraping
        $json = $this->structuredData($html);
        $xpath = $this->xpath($html);

        $name = $this->clean((string) ($json['name'] ?? ''))
            ?: $this->text($xpath, '//h1');

        if ($name === '' || $name === null) {
            return null;
        }

        return [
            'name' => $name,
            'description' => $this->description($json, $xpath, $html),
            'images' => $this->images($json, $html),
            'specs' => $this->specs($xpath),
            'in_stock' => ! isset($json['offers']['availability'])
                || str_contains((string) $json['offers']['availability'], 'InStock'),
        ];
    }

    /* ------------------------------------------------------------------ parts */

    protected function structuredData(string $html): array
    {
        if (! preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m)) {
            return [];
        }

        $decoded = json_decode($m[1], true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }

    protected function description(array $json, ?DOMXPath $xpath, string $html): ?string
    {
        if (! empty($json['description'])) {
            return $this->clean($json['description']);
        }

        foreach ([
            '//*[@id="description" or @id="tab-description"]',
            '//*[contains(@class,"product-description")]',
            '//*[contains(@class,"full-description")]',
        ] as $query) {
            if ($text = $this->text($xpath, $query)) {
                return $text;
            }
        }

        // the social preview is the last honest summary a page has
        return preg_match('#<meta property="og:description" content="([^"]+)"#i', $html, $m)
            ? $this->clean($m[1])
            : null;
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

        if (! $images && preg_match('#<meta property="og:image" content="([^"]+)"#i', $html, $m)) {
            $images[] = $m[1];
        }

        if (preg_match_all('#<img[^>]+src="([^"]+/images/thumbs/[^"]+)"#i', $html, $m)) {
            foreach ($m[1] as $src) {
                $images[] = str_starts_with($src, 'http') ? $src : self::BASE.$src;
            }
        }

        return array_values(array_unique(array_filter($images)));
    }

    /**
     * The spec table, read as label/value rows.
     *
     * @return array<int, array{name:string, value:string}>
     */
    protected function specs(?DOMXPath $xpath): array
    {
        if (! $xpath) {
            return [];
        }

        $specs = [];

        foreach (['//table//tr[td]', '//dl/dt'] as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                if ($node->nodeName === 'dt') {
                    $value = $this->clean($node->nextElementSibling?->textContent ?? '');
                    $name = $this->clean($node->textContent);
                } else {
                    $cells = [];

                    foreach ($node->childNodes as $cell) {
                        if ($text = $this->clean($cell->textContent ?? '')) {
                            $cells[] = $text;
                        }
                    }

                    if (count($cells) < 2) {
                        continue;
                    }

                    [$name, $value] = $cells;
                }

                $name = trim($name, ' :：');

                if ($name !== '' && $value !== '' && $value !== '-') {
                    $specs[$name] = ['name' => $name, 'value' => $value];
                }
            }

            if ($specs) {
                break;   // the first shape that yields anything is the right one
            }
        }

        return array_values($specs);
    }

    /* ------------------------------------------------------------------ http */

    protected function get(string $url, array $query = [], array $headers = []): ?string
    {
        $response = Http::timeout($this->config['timeout'] ?? config('shop.import_request_timeout'))
            ->connectTimeout(10)
            ->retry(2, 1500, throw: false)
            ->withHeaders($headers + [
                'User-Agent' => config('services.ingco.user_agent', 'Mozilla/5.0'),
                'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
                'Accept-Language' => 'ka,en;q=0.9',
                'Referer' => self::BASE.'/',
            ])
            ->get($url, $query);

        return $response->successful() ? $response->body() : null;
    }

    protected function xpath(string $html): ?DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML('<?xml encoding="UTF-8">'.$html);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    protected function text(?DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath?->query($query)?->item(0);

        return $node ? ($this->clean($node->textContent) ?: null) : null;
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
