<?php

namespace App\Services\Import\Drivers\Midea;

use App\Support\SharedHttp;

/**
 * midea.ge, through the search its own header uses.
 *
 * One POST to /ka/search with a keyword answers with the whole product: the
 * title, the description, both prices, the main photograph, the gallery, the
 * model number, the stock flag and the weight. There is nothing else to ask
 * it for.
 *
 * This used to crawl seven category listings page by page, index every card by
 * model code, fall back to the sitemap when the index missed, and then fetch
 * the product page when the card had no picture — four ways in, a six hour
 * cache, and hundreds of requests to learn less than this one does.
 */
class MideaClient
{
    /** Below this a word matches half the catalogue and is not worth a request. */
    protected const MIN_KEYWORD_LENGTH = 5;

    public function __construct(protected array $config = []) {}

    /**
     * One product, as a card the driver can use.
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
            foreach ($this->search($keyword) as $product) {
                if ($this->isSameModel($key, (string) ($product['model_number'] ?? ''))) {
                    return $this->toCard($product);
                }
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ asking */

    /**
     * The keywords worth asking for, in order.
     *
     * The search matches the whole string, and any extra word in it kills the
     * result outright — "MDRB489FGE02OE" finds the fridge, "Midea
     * MDRB489FGE02OE" and "MDRB489FGE02OE INOX" both find nothing. Which is
     * what a spreadsheet hands us: a model with the brand in front of it, or a
     * colour on the end, written differently in every row.
     *
     * So the whole string first, because it is usually right and costs one
     * request, and then its words longest first — the model code is the
     * longest thing in a cell like "Midea MDRB489FGE02OE INOX". Short words
     * are not worth asking about: "INV" matches half the catalogue and the
     * brand matches all of it.
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
            fn ($word) => mb_strlen($word) >= self::MIN_KEYWORD_LENGTH && mb_strtolower($word) !== $brand,
        ));

        return array_merge([$model], $words);
    }

    /**
     * The search endpoint, as the site's own script calls it.
     *
     * A POST, which is the whole reason this took so long to find: every GET
     * shape answers {"status":0,"products":null}, which reads like "nothing
     * found" and means "I did not understand the request".
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $keyword): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        $url = $this->absolute('/ka/search');

        $response = SharedHttp::to($url)
            ->timeout($this->config['timeout'] ?? config('shop.import_request_timeout'))
            ->connectTimeout(15)
            ->asForm()
            ->withHeaders([
                'User-Agent' => config('services.midea.user_agent', 'Mozilla/5.0'),
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => rtrim((string) config('services.midea.base_url'), '/').'/ka/',
            ])
            ->post($url, ['keyword' => $keyword]);

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
    protected function toCard(array $product): array
    {
        return [
            'id' => $product['id'] ?? null,
            'url' => $this->normalise((string) ($product['link'] ?? '')),
            'name' => $this->clean((string) ($product['title'] ?? '')),
            'description' => $this->clean((string) ($product['text'] ?? $product['short_text'] ?? '')) ?: null,
            'category' => $this->clean((string) ($product['description_title'] ?? '')) ?: null,
            'images' => $this->images($product),
            'in_stock' => (string) ($product['in_stock'] ?? '1') === '1',
            'weight' => (int) ($product['weight'] ?? 0) ?: null,
        ];
    }

    /**
     * Whether the result is the model that was asked for.
     *
     * The site writes a model number as the bare code on one product and with
     * the brand in front of it on the next — "FS40-21MR" against "Midea
     * MDRB489FGE02OE" — so neither side can be required to equal the other,
     * only to contain it.
     *
     * Checked at all because a search is a search: ask it for "MFM-60" and it
     * answers with MFM-60ARN1-RB6, and a product wearing another product's
     * photographs is worse than one with none.
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
    protected function images(array $product): array
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

    /* ------------------------------------------------------------------ helpers */

    /** The site writes its own links with a doubled slash after the locale. */
    protected function normalise(string $url): string
    {
        return preg_replace('#(?<!:)//+#', '/', trim($url));
    }

    protected function modelKey(string $model): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $model));
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
            preg_replace('#<br\s*/?>#i', ' ', $value),
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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
