<?php

namespace App\Services\Import\Drivers\Citrus;

use Illuminate\Support\Facades\Http;

/**
 * citrus.ge, through the API its own front end uses.
 *
 * The site is a JavaScript shell — fetching a product page gives nine
 * kilobytes of loader and no product — but every page it draws comes from
 * /api/product/{slug}, which answers with the whole thing: the name, both
 * prices, the stock, the manufacturer, the gallery, the specification list
 * and the breadcrumb.
 *
 * So nothing here is scraped. getProduct() takes the address a person would
 * paste from their browser and returns what that endpoint said.
 */
class CitrusClient
{
    protected const BASE = 'https://citrus.ge';

    public function __construct(protected array $config = []) {}

    /**
     * The product behind a citrus.ge address.
     *
     * @return array<string, mixed>|null
     */
    public function getProduct(string $url): ?array
    {
        $slug = $this->slug($url);

        return $slug ? $this->bySlug($slug) : null;
    }

    /** @return array<string, mixed>|null */
    public function bySlug(string $slug): ?array
    {
        $response = Http::timeout($this->config['timeout'] ?? 30)
            ->connectTimeout(10)
            // every other client in this import carries it: a host that
            // resolves to an unroutable address hangs rather than refusing
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->retry(2, 500, throw: false)
            ->withHeaders([
                'User-Agent' => config('services.citrus.user_agent', 'Mozilla/5.0'),
                'Accept' => 'application/json',
                'Referer' => self::BASE.'/',
            ])
            ->get($this->base().'/api/product/'.$slug);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) && ! empty($data['id']) ? $this->take($data, $slug) : null;
    }

    /**
     * The slug out of whatever was pasted.
     *
     * People paste the address bar, which carries a locale, a query string and
     * sometimes a trailing slash — and sometimes they paste the slug alone.
     * All of it reduces to the one segment the API wants.
     */
    public function slug(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // a bare slug, pasted without the address around it
        if (! str_contains($url, '/')) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $parts = array_values(array_filter(explode('/', $path)));

        if (! $parts) {
            return null;
        }

        $at = array_search('product', $parts, true);

        return $at !== false
            ? ($parts[$at + 1] ?? null)
            : end($parts);
    }

    /* ------------------------------------------------------------------ reading */

    /** @return array<string, mixed> */
    protected function take(array $data, string $slug): array
    {
        $price = (float) ($data['price'] ?? 0);
        $old = (float) ($data['old_price'] ?? 0);

        return [
            'id' => (string) $data['id'],
            'sku' => trim((string) ($data['sku'] ?? '')),
            'slug' => $slug,
            'name' => $this->clean((string) ($data['name'] ?? '')),
            'brand' => $this->clean((string) ($data['manufacturer']['name'] ?? '')) ?: null,
            'category' => $this->category($data),

            'price' => $price,
            // the site shows old_price struck through, so it is the higher one
            'old_price' => $old > $price ? $old : null,

            'stock' => (int) ($data['stock'] ?? 0),
            'in_stock' => (int) ($data['stock_status'] ?? 0) > 0,

            'description' => $this->clean((string) ($data['details']['description'] ?? '')) ?: null,
            'summary' => $this->clean((string) ($data['details']['mini_description'] ?? '')) ?: null,
            'weight' => $this->weight($data['details']['weight'] ?? null),
            'warranty' => $this->clean((string) ($data['details']['warranty'] ?? '')) ?: null,

            'images' => $this->images($data),
            'attributes' => $this->attributes($data),
        ];
    }

    /**
     * The narrowest step of the breadcrumb, for the mapping queue.
     *
     * The trail reads Technics › Climate › Water heaters, so the last step is
     * the one worth mapping — the product itself is not in it.
     */
    protected function category(array $data): ?string
    {
        $names = [];

        foreach ((array) ($data['breadcrumb'] ?? []) as $crumb) {
            $name = $this->clean((string) ($crumb['name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names ? end($names) : null;
    }

    /**
     * Every photograph, largest first.
     *
     * large_images is a plain list of addresses; images is the same pictures
     * with their colour attached. The first is enough, and the second fills in
     * for a product that has only it.
     *
     * @return array<int, string>
     */
    protected function images(array $data): array
    {
        $urls = [];

        foreach ((array) ($data['large_images'] ?? []) as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                $urls[] = $url;
            }
        }

        foreach ((array) ($data['images'] ?? []) as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : $image;

            if (is_string($url) && str_starts_with($url, 'http')) {
                $urls[] = $url;
            }
        }

        if ($main = $data['image'] ?? null) {
            if (is_string($main) && str_starts_with($main, 'http')) {
                array_unshift($urls, $main);
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The specification list.
     *
     * "type": "checkbox" is how the site marks the ones it filters its own
     * catalogue by, which is a better guess at what is worth filtering on than
     * anything we could infer.
     *
     * @return array<int, array{name:string, value:string, filterable:bool}>
     */
    protected function attributes(array $data): array
    {
        $specs = [];

        foreach ((array) ($data['attributes'] ?? []) as $attribute) {
            $name = $this->clean((string) ($attribute['name'] ?? ''));
            $value = $this->clean((string) ($attribute['value'] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $specs[$name] = [
                'name' => $name,
                'value' => $value,
                'filterable' => ($attribute['type'] ?? null) === 'checkbox',
            ];
        }

        return array_values($specs);
    }

    /** "12.5 kg", "12500" — grams, which is what the delivery calculator wants. */
    protected function weight(mixed $raw): ?int
    {
        $text = trim((string) $raw);

        if ($text === '' || ! preg_match('/[\d.]+/', $text, $m)) {
            return null;
        }

        $number = (float) $m[0];

        // anything under a thousand was written in kilograms
        return (int) round($number < 1000 ? $number * 1000 : $number) ?: null;
    }

    protected function base(): string
    {
        return rtrim((string) config('services.citrus.base_url', self::BASE), '/');
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/[ \t]+/u', ' ', html_entity_decode(strip_tags(
            preg_replace('#<br\s*/?>|</p>|</li>#i', "\n", $value),
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
