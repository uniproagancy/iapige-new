<?php

namespace App\Services\Import\Drivers\Midea;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        /*
         * Two columns carry a model and neither is reliably filled: K is what
         * the website calls it, B is what the warehouse calls it. Only the
         * first was ever tried, so a row with an empty K and a perfectly good
         * B was never looked up at all.
         */
        $models = array_values(array_unique(array_filter([
            trim((string) ($data['site_model'] ?? '')),
            trim((string) ($data['model'] ?? '')),
        ])));

        return $this->toPayload($externalId, $row, $data, $this->siteCard(...$models));
    }

    /* ------------------------------------------------------------------ site */

    /**
     * The site's card for a model, crawled once per run and cached. Matching is
     * on the model code because the spreadsheet and the site agree on nothing
     * else — not even the product's name.
     */
    protected function siteCard(string ...$models): array
    {
        /*
         * The website is an enrichment, never a requirement.
         *
         * Everything that decides whether a product exists and what it costs
         * comes from the price list; the site only adds a title, a photograph
         * and a description. So a site that is slow, down, or has moved a page
         * must cost the product its picture and nothing else.
         *
         * It cost far more than that. midea.ge's listing pages began timing
         * out at ten seconds each, the exception travelled up through fetch()
         * and the job caught it — and the product was skipped entirely. Not
         * imported without a photograph: not imported at all, though the
         * spreadsheet had its price and its stock the whole time. One slow
         * page on their side emptied our catalogue of everything the search
         * had not already found.
         */
        try {
            return $this->readSite(...$models);
        } catch (\Throwable $e) {
            Log::channel('import')->warning('the site could not be read; importing without it', [
                'supplier' => $this->supplier->code,
                'models' => $models,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /** @return array<string, mixed> */
    protected function readSite(string ...$models): array
    {
        $models = array_values(array_filter($models));

        if (! $models || ! ($this->supplier->config['read_site'] ?? true)) {
            return [];
        }

        $model = $models[0];

        /*
         * The site's own search first.
         *
         * It answers with the whole product — title, both prices, the main
         * photograph and the gallery — in one request. Everything below this
         * is the long way round: crawl seven category listings page by page,
         * index every card by model code, fall back to the sitemap, then fetch
         * the product page when the card had no picture. It is kept because it
         * still finds the occasional model the search does not, and it costs
         * nothing when the search answers.
         */
        foreach ($models as $candidate) {
            if ($card = $this->client->card($candidate)) {
                return $card;
            }
        }

        $index = $this->remember('site-index', fn () => $this->crawl());

        $key = $this->modelKey($model);

        if (isset($index[$key])) {
            $card = $index[$key];

            /*
             * A card with no picture has to ask the product page for one.
             *
             * The card's only image is a CSS background on the thumbnail, and
             * that one parse is all a listing product ever got: read_product_page
             * is off, and needs_page was set for sitemap matches alone. So a card
             * whose style attribute did not yield a url — a lazy-loaded
             * thumbnail, a markup change on one template, an empty placeholder
             * while the site's own cache warmed — produced a product with no
             * photograph at all and nothing to fall back on. That is the
             * "sometimes" in it: the spreadsheet and the price were fine, the
             * picture silently was not.
             *
             * Only when there is nothing, so a card that already has its
             * thumbnail still costs no second request.
             */
            if (empty($card['image'])) {
                $card['needs_page'] = true;
            }

            return $card;
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
        if ($key === '') {
            return null;
        }

        /*
         * A short code is matched at the end of the slug, not anywhere in it.
         *
         * Containment was the only rule, so anything under five characters was
         * refused outright rather than risk "MO37" picking the first slug that
         * happened to contain those four. Refusing it meant every short-coded
         * product — and Midea has plenty — came in with no picture at all.
         *
         * The slug ends with the model, which the comment above says and the
         * examples bear out, so anchoring there is as safe as a long code and
         * recovers the rest.
         */
        $anchored = mb_strlen($key) < self::MIN_MATCH_LENGTH;

        foreach ($this->sitemapIndex() as $slug => $url) {
            $hit = $anchored ? str_ends_with($slug, $key) : str_contains($slug, $key);

            if ($hit) {
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
        return $this->remember('sitemap-index', function () {
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
        });
    }

    /**
     * An index, kept for the run — but never an empty one.
     *
     * Cache::remember stores whatever the closure returns, including nothing.
     * So a crawl that came back empty because the site was down, or slow, or
     * had moved a class name, was written down as the truth and every product
     * imported for the next six hours arrived with no picture. The run after
     * it looked identical and was no better, which is what made this hard to
     * see: nothing was failing by then, the answer was simply already cached.
     *
     * An empty result is now treated as a failed attempt, and the next product
     * asks again.
     *
     * @param  \Closure():array  $build
     * @return array<string, mixed>
     */
    protected function remember(string $name, \Closure $build): array
    {
        $key = "import:{$this->supplier->code}:{$name}";

        $index = Cache::get($key);

        if (is_array($index) && $index !== []) {
            return $index;
        }

        /*
         * A source that could not be reached is not asked again immediately.
         *
         * An empty result is never cached as the truth — a crawl that came
         * back empty because the site was down would otherwise be believed for
         * six hours. But not caching the failure at all is its own trap: every
         * product then repeats the whole crawl, and midea.ge's listing pages
         * were timing out at ten seconds each, so a single product spent half
         * a minute learning what the one before it had just learned. Two
         * hundred products is an afternoon of that.
         *
         * So the failure is remembered, briefly. Long enough that one run does
         * not repeat it hundreds of times, short enough that the next run
         * tries again.
         */
        if (Cache::get($key.':failed')) {
            return [];
        }

        try {
            $index = $build();
        } catch (\Throwable $e) {
            Cache::put($key.':failed', true, now()->addMinutes(self::RETRY_AFTER_FAILURE));

            Log::channel('import')->warning('could not build the site index', [
                'supplier' => $this->supplier->code,
                'index' => $name,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($index === []) {
            Cache::put($key.':failed', true, now()->addMinutes(self::RETRY_AFTER_FAILURE));

            return [];
        }

        Cache::put($key, $index, now()->addHours(6));

        return $index;
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

        /*
         * A listing card carries one thumbnail; a search result carries the
         * gallery. Merged rather than chosen between, so a product found both
         * ways keeps every picture either of them knows about.
         */
        $images = array_values(array_unique(array_filter(array_merge(
            [$card['image'] ?? null],
            $card['images'] ?? [],
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
                // the search's own copy reads better than the spreadsheet's line
                'description' => $card['description'] ?? $page['description'] ?? $data['description'] ?? null,
            ]],
            specs: array_values($specs),
            images: $images,
            // the search reports it; the spreadsheet never did
            weight: $card['weight'] ?? null,
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
    /** Minutes before a source that could not be reached is asked again. */
    protected const RETRY_AFTER_FAILURE = 10;

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
