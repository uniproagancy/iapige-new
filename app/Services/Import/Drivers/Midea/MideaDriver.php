<?php

namespace App\Services\Import\Drivers\Midea;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Support\Facades\Cache;

/**
 * Midea's price list is the catalogue: it carries the category, the model, the
 * barcode, a description, three prices and the stock. The website adds only
 * photographs and a few specifications, matched on the model code.
 *
 * So the spreadsheet decides what exists and what it costs, and the crawl is an
 * enrichment that the import survives without.
 */
class MideaDriver implements SupplierDriver
{
    protected MideaClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new MideaClient($supplier->config ?? []);
    }

    /** Every barcode in the last uploaded price list. */
    public function ids(): iterable
    {
        return SupplierStock::where('supplier_id', $this->supplier->id)
            ->orderBy('external_id')
            ->lazyById(500)
            ->map(fn (SupplierStock $s) => $s->external_id);
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        $row = SupplierStock::where('supplier_id', $this->supplier->id)
            ->where('external_id', $externalId)
            ->first();

        if (! $row) {
            return null;
        }

        $data = $row->data ?? [];
        $model = trim((string) ($data['site_model'] ?? $data['model'] ?? ''));

        return $this->toPayload($externalId, $row, $data, $this->siteCard($model));
    }

    /* ------------------------------------------------------------------ site */

    /**
     * The site's card for a model, crawled once per run and cached. Matching is
     * on the model code because the spreadsheet and the site agree on nothing
     * else — not even the product's name.
     */
    protected function siteCard(string $model): array
    {
        if ($model === '' || ! ($this->supplier->config['read_site'] ?? true)) {
            return [];
        }

        $index = Cache::remember(
            "import:{$this->supplier->code}:site-index",
            now()->addHours(6),
            fn () => $this->crawl(),
        );

        $key = $this->modelKey($model);

        if (isset($index[$key])) {
            return $index[$key];
        }

        /*
         * Nothing in the listings, so ask the sitemap.
         *
         * The listings are seven narrow sub-pages and the price list spans
         * twenty-one categories, so most models were never looked for at all —
         * the product came in from the spreadsheet and simply had no picture.
         * A sitemap entry carries no card, only an address, so the product page
         * is read for it; that is marked rather than assumed, because a listing
         * card already has its picture and needs no second request.
         */
        if ($url = $this->sitemapMatch($key)) {
            return ['url' => $url, 'needs_page' => true];
        }

        return [];
    }

    /**
     * The sitemap address whose slug carries this model code.
     *
     * Matched by containment rather than equality, because the slug is the
     * product's whole name with the model buried at the end and written in
     * lower case — "chasashenebeli-eleqtro-gumeli-mo-37001-gb" for MO-37001-GB.
     * Pulling the model back out of that is guesswork; asking whether the slug
     * contains it is not.
     *
     * Short codes are left alone: three or four characters appear inside
     * unrelated slugs often enough that a wrong picture is the likely outcome.
     */
    protected function sitemapMatch(string $key): ?string
    {
        if (mb_strlen($key) < self::MIN_MATCH_LENGTH) {
            return null;
        }

        foreach ($this->sitemapIndex() as $slug => $url) {
            if (str_contains($slug, $key)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Normalised slug => product address, from the site's sitemap.
     *
     * The whole slug is kept rather than a model pulled out of it: the address
     * is /ka/product/<slug>/<id>/ and the slug is the product's name with the
     * model at the end in lower case, so there is no reliable place to cut. The
     * same normalisation the listing index uses is applied to both sides, and
     * the lookup asks whether the slug contains the code.
     *
     * @return array<string, string>
     */
    protected function sitemapIndex(): array
    {
        return Cache::remember(
            "import:{$this->supplier->code}:sitemap-index",
            now()->addHours(6),
            function () {
                $locale = $this->supplier->config['locale'] ?? 'ka';
                $index = [];

                foreach ($this->client->sitemapProducts($locale) as $url) {
                    // the trailing number is the site's own id, not the model
                    $slug = basename(rtrim((string) preg_replace('#/\d+/?$#', '', $url), '/'));
                    $key = $this->modelKey($slug);

                    if ($key !== '') {
                        $index[$key] ??= $url;
                    }
                }

                return $index;
            },
        );
    }

    /**
     * Walks the configured listings once and indexes every card by model code.
     *
     * @return array<string, array>
     */
    protected function crawl(): array
    {
        $index = [];
        $maxPages = (int) ($this->supplier->config['max_pages'] ?? 30);

        foreach ((array) ($this->supplier->config['listings'] ?? []) as $listing) {
            for ($page = 1; $page <= $maxPages; $page++) {
                $cards = $this->client->listing($this->pageUrl($listing, $page));

                if (! $cards) {
                    break;   // past the last page of this category
                }

                foreach ($cards as $card) {
                    $code = $this->modelCode($card['model'] ?? $card['name'] ?? '');

                    if ($code) {
                        $index[$this->modelKey($code)] = $card;
                    }
                }
            }
        }

        return $index;
    }

    protected function pageUrl(string $listing, int $page): string
    {
        $url = $this->client->absolute($listing);

        return $page === 1 ? $url : preg_replace('#/all/\d+/\d+/?$#', "/all/1/{$page}/", $url);
    }

    /* ------------------------------------------------------------------ mapping */

    protected function toPayload(string $barcode, SupplierStock $row, array $data, array $card): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        $model = trim((string) ($data['model'] ?? ''));
        $name = $this->name($data, $card, $model);

        if ($name === '') {
            return null;
        }

        [$price, $old] = $this->prices($data, $row);

        $specs = [];

        // the description is a comma-separated feature list, not a spec table,
        // so only the site's "label: value" pairs become specs
        foreach (array_merge($card['specs'] ?? [], $this->specsFromRow($data)) as $spec) {
            $specs[$spec['name']] = [
                'name' => $spec['name'],
                'value' => $spec['value'],
                'locale' => $locale,
                'key' => false,
                'filterable' => false,   // filters are chosen in the admin
                'color' => null,
            ];
        }

        // a sitemap match has no card, so its page is the only picture there is
        $readPage = ! empty($card['needs_page'])
            || ($this->supplier->config['read_product_page'] ?? false);

        $page = ! empty($card['url']) && $readPage
            ? $this->client->page($card['url'])
            : [];

        foreach ($page['specs'] ?? [] as $spec) {
            $specs[$spec['name']] ??= [
                'name' => $spec['name'],
                'value' => $spec['value'],
                'locale' => $locale,
                'key' => false,
                'filterable' => false,
                'color' => null,
            ];
        }

        $images = array_values(array_unique(array_filter(array_merge(
            [$card['image'] ?? null],
            $page['images'] ?? [],
        ))));

        return new ProductPayload(
            externalId: $barcode,
            // the barcode is the supplier's own identity and never changes
            sku: $this->supplier->code.'-'.$barcode,
            costPrice: $price,
            oldCostPrice: $old,
            stock: (int) $row->quantity,
            brandName: $this->supplier->config['brand'] ?? 'Midea',
            categoryName: $data['category'] ?? null,
            translations: [$locale => [
                'name' => $name,
                'summary' => $data['description'] ?? null,
                'description' => $page['description'] ?? $data['description'] ?? null,
            ]],
            specs: array_values($specs),
            images: $images,
            weight: null,
        );
    }

    /**
     * The spreadsheet's name is a bare model code, which reads badly in a shop,
     * so the site's title wins when the two describe the same product.
     */
    protected function name(array $data, array $card, string $model): string
    {
        $fromSite = trim((string) ($card['name'] ?? ''));

        if ($fromSite !== '') {
            return $fromSite;
        }

        $category = trim((string) ($data['category'] ?? ''));

        return trim($category !== '' ? "{$category} {$model}" : $model);
    }

    /**
     * Three prices arrive: the retail list, the retail promotion and the dealer
     * promotion. What we pay is the dealer price; what the list is worth is the
     * retail one, so the markup rules decide the rest.
     *
     * @return array{float, ?float}
     */
    protected function prices(array $data, SupplierStock $row): array
    {
        $dealer = $this->money($data['cost'] ?? null) ?? (float) $row->cost_price;
        $retailSale = $this->money($data['retail_sale'] ?? null);
        $retail = $this->money($data['retail'] ?? null);

        if (($this->supplier->config['price_from'] ?? 'dealer') === 'retail') {
            // sell at the supplier's own promotional price, with the list price struck through
            return [$retailSale ?: $retail ?: $dealer, $retailSale && $retail > $retailSale ? $retail : null];
        }

        return [$dealer, null];
    }

    /** Warranty is the one spreadsheet field that reads as a specification. */
    protected function specsFromRow(array $data): array
    {
        $specs = [];

        if (! empty($data['warranty'])) {
            $specs[] = ['name' => __('product.warranty'), 'value' => $data['warranty']];
        }

        return $specs;
    }

    /** "სარეცხის მანქანა MF100W60" → "MF100W60" */
    protected function modelCode(string $text): ?string
    {
        return preg_match('/([A-Z0-9][A-Z0-9\-\/]{3,})/u', $text, $m) ? $m[1] : null;
    }

    /** Model codes differ in case and punctuation between the file and the site. */
    /** Below this a code appears inside unrelated slugs too often to trust. */
    protected const MIN_MATCH_LENGTH = 5;

    protected function modelKey(string $model): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $model));
    }

    protected function money(?string $text): ?float
    {
        $digits = preg_replace('/[^\d.]/', '', str_replace(',', '.', (string) $text));

        return $digits === '' ? null : (float) $digits;
    }
}
