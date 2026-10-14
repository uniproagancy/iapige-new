<?php

namespace App\Services\Import\Drivers\Midea;

use Illuminate\Support\Facades\Http;

/**
 * midea.ge, through the search its own header uses.
 *
 * One POST to /ka/search answers with everything about a product: the title,
 * the description, both prices, the main photograph, the gallery, the model
 * number and the stock flag. There is nothing else to ask the site for.
 *
 * searchByName() returns the fields the working version of this importer was
 * written against — sku, name, brand, price, sale_price, description, images
 * — so the driver reads what it always read.
 */
class MideaClient
{
    public function __construct(protected array $config = []) {}

    /**
     * The product, or nothing.
     *
     * @return array<string, mixed>|null
     */
    public function searchByName(string $name): ?array
    {
        $wanted = $this->key($name);

        if ($wanted === '') {
            return null;
        }

        foreach ($this->keywords($name) as $keyword) {
            foreach ($this->search($keyword) as $product) {
                if ($this->matches($wanted, (string) ($product['model_number'] ?? ''))) {
                    return $this->take($product);
                }
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ asking */

    /**
     * What to ask for, in order.
     *
     * The search matches the whole string and any extra word kills it:
     * "MDRB489FGE02OE" finds the fridge, "Midea MDRB489FGE02OE" and
     * "MDRB489FGE02OE INOX" both find nothing. A price list writes it all
     * three ways, so the whole string is tried first and then its words,
     * longest first — the model code is the longest thing in the cell.
     *
     * @return array<int, string>
     */
    protected function keywords(string $name): array
    {
        $name = trim($name);
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) < 2) {
            return [$name];
        }

        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $brand = mb_strtolower((string) ($this->config['brand'] ?? 'midea'));

        // a short word matches half the catalogue and the brand matches all of it
        $words = array_filter($words, fn ($w) => mb_strlen($w) >= 5 && mb_strtolower($w) !== $brand);

        return array_merge([$name], array_values($words));
    }

    /**
     * The endpoint, called the way the site's own script calls it.
     *
     * A POST. Every GET shape answers {"status":0,"products":null}, which
     * reads like "nothing found" and means "I did not understand you".
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $keyword): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        $base = $this->base();

        $response = Http::timeout($this->config['timeout'] ?? 30)
            ->connectTimeout(10)
            ->asForm()
            ->withHeaders([
                'User-Agent' => config('services.midea.user_agent', 'Mozilla/5.0'),
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => $base.'/ka/',
            ])
            ->post($base.'/ka/search', ['keyword' => $keyword]);

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();

        return (int) ($body['status'] ?? 0) === 1 && is_array($body['products'] ?? null)
            ? $body['products']
            : [];
    }

    /* ------------------------------------------------------------------ reading */

    /** @return array<string, mixed> */
    protected function take(array $product): array
    {
        $price = (float) ($product['price'] ?? 0);
        $sale = (float) ($product['sale_price'] ?? 0);

        return [
            'sku' => trim((string) ($product['model_number'] ?? $product['id'] ?? '')),
            'name' => $this->clean((string) ($product['title'] ?? '')),
            'brand' => $this->config['brand'] ?? 'Midea',
            'price' => $price,
            // the site writes 0 for "no discount", which is not a price
            'sale_price' => $sale > 0 && $sale < $price ? $sale : null,
            'description' => $this->clean((string) ($product['text'] ?? $product['short_text'] ?? '')) ?: null,
            'images' => $this->images($product),
            'in_stock' => (string) ($product['in_stock'] ?? '1') === '1',
            'weight' => (int) ($product['weight'] ?? 0) ?: null,
        ];
    }

    /**
     * Whether a result is the model that was asked for.
     *
     * A search is a search: ask it for "MFM-60" and it answers with
     * MFM-60ARN1-RB6. A product wearing another product's photographs is
     * worse than one with none.
     *
     * Neither side can be required to equal the other, because the site
     * writes the bare code on one product and the brand in front of it on the
     * next.
     */
    protected function matches(string $wanted, string $found): bool
    {
        $found = $this->key($found);

        return $found !== '' && (str_contains($found, $wanted) || str_contains($wanted, $found));
    }

    /**
     * The main photograph and the gallery.
     *
     * Both arrive as bare filenames under the site's media path, and the
     * gallery arrives as JSON inside the JSON.
     *
     * @return array<int, string>
     */
    protected function images(array $product): array
    {
        $path = '/'.trim((string) config('services.midea.image_path', '/uploads/products/'), '/').'/';
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
            fn (string $file) => $this->base().$path.ltrim($file, '/'),
            $files,
        )));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function base(): string
    {
        return rtrim((string) config('services.midea.base_url', 'https://www.midea.ge'), '/');
    }

    /** Model codes differ in case and punctuation between a file and the site. */
    protected function key(string $model): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $model));
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
            preg_replace('#<br\s*/?>#i', ' ', $value),
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
