<?php

namespace App\Services\Import\Drivers\Ingco;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;

class IngcoClient
{
    protected const BASE = 'https://ingco.ge';

    public function __construct(protected array $config = []) {}

    public function findUrl(string $model): ?string
    {
        $response = $this->get(self::BASE.'/ka/catalog/searchtermautocomplete', [
            'term' => $model,
            'pageIndex' => 0,
        ], ['X-Requested-With' => 'XMLHttpRequest']);

        if ($response === null) {
            return null;
        }

        $html = json_decode($response) ?: $response;

        return preg_match('#href="(/ka/[^"]+)"#', (string) $html, $m)
            ? self::BASE.$m[1]
            : null;
    }

    public function page(string $url): ?array
    {
        $html = $this->get($url);

        if ($html === null) {
            return null;
        }

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
            'category' => $this->category($html, $xpath),
            'images' => $this->images($json, $html),
            'specs' => $this->specs($xpath),
            'in_stock' => ! isset($json['offers']['availability'])
                || str_contains((string) $json['offers']['availability'], 'InStock'),
        ];
    }

    protected function structuredData(string $html): array
    {
        return $this->ldJson($html, 'Product') ?? [];
    }

    protected function ldJson(string $html, string $type): ?array
    {
        if (! preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $m)) {
            return null;
        }

        foreach ($m[1] as $raw) {
            $decoded = json_decode(trim($raw), true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ([$decoded, ...array_values((array) ($decoded['@graph'] ?? []))] as $node) {
                if (! is_array($node)) {
                    continue;
                }

                foreach ((array) ($node['@type'] ?? []) as $declared) {
                    if (is_string($declared) && str_ends_with($declared, $type)) {
                        return $node;
                    }
                }
            }
        }

        return null;
    }

    protected function category(string $html, ?DOMXPath $xpath): ?string
    {
        $names = [];

        foreach ($this->ldJson($html, 'BreadcrumbList')['itemListElement'] ?? [] as $item) {
            $name = $this->clean((string) ($item['name'] ?? $item['item']['name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        if (! $names && $xpath) {
            foreach ($xpath->query('//*[contains(@class,"breadcrumb")]//a') ?: [] as $node) {
                $name = $this->clean($node->textContent);

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        if ($names && $this->isProductName($names[count($names) - 1], $html)) {
            array_pop($names);
        }

        $category = array_pop($names);

        return in_array($category, ['მთავარი', 'Home', 'Главная', 'მაღაზია', 'Shop'], true)
            ? null
            : ($category ?: null);
    }

    protected function isProductName(string $name, string $html): bool
    {
        $title = $this->clean((string) ($this->structuredData($html)['name'] ?? ''));

        return $title !== '' && mb_strtolower($title) === mb_strtolower($name);
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

        return preg_match('#<meta property="og:description" content="([^"]+)"#i', $html, $m)
            ? $this->clean($m[1])
            : null;
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
                break;
            }
        }

        return array_values($specs);
    }

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
