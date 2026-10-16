<?php

namespace App\Services\Import\Drivers\Comfo;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class ComfoClient
{
    protected const KA_TO_EN = [
        'ა' => 'a', 'ბ' => 'b', 'გ' => 'g', 'დ' => 'd', 'ე' => 'e', 'ვ' => 'v', 'ზ' => 'z', 'თ' => 't',
        'ი' => 'i', 'კ' => 'k', 'ლ' => 'l', 'მ' => 'm', 'ნ' => 'n', 'ო' => 'o', 'პ' => 'p', 'ჟ' => 'zh',
        'რ' => 'r', 'ს' => 's', 'ტ' => 't', 'უ' => 'u', 'ფ' => 'p', 'ქ' => 'k', 'ღ' => 'gh', 'ყ' => 'y',
        'შ' => 'sh', 'ჩ' => 'ch', 'ც' => 'ts', 'ძ' => 'dz', 'წ' => 'w', 'ჭ' => 'ch', 'ხ' => 'kh',
        'ჯ' => 'j', 'ჰ' => 'h',
    ];

    public function __construct(protected array $config = []) {}

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

            ->retry(2, 1500, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
            ->withHeaders([
                'User-Agent' => $this->config['user_agent'] ?? 'Mozilla/5.0',
                'Accept-Language' => 'ka',
            ])
            ->get($url);

        return $response->successful() ? $response->body() : null;
    }

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

    protected function category(array $json, string $html): ?string
    {
        $category = $json['category'] ?? null;
        $category = is_array($category) ? ($category['name'] ?? null) : $category;

        if (is_string($category) && ($category = $this->clean($category)) !== '') {

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

        array_pop($names);
        $category = array_pop($names);

        return in_array($category, ['მთავარი', 'Home', 'Главная'], true) ? null : ($category ?: null);
    }

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
