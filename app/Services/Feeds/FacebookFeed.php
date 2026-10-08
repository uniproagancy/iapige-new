<?php

namespace App\Services\Feeds;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use XMLWriter;

/**
 * Builds the Facebook / Instagram product feed.
 *
 * Written straight to disk with XMLWriter instead of collected in memory: at
 * tens of thousands of products an array of items is the thing that runs the
 * process out of memory, not the database.
 */
class FacebookFeed
{
    protected array $config;

    protected int $written = 0;

    protected array $skipped = ['price' => 0, 'no_name' => 0, 'no_image' => 0, 'no_category' => 0];

    public function __construct()
    {
        $this->config = config('feeds.facebook');
    }

    /** Generates the feed and returns the path it was written to. */
    public function generate(): string
    {
        $locale = $this->config['locale'];
        $previous = app()->getLocale();
        app()->setLocale($locale);

        $path = Storage::disk('local')->path($this->config['path']);
        @mkdir(dirname($path), 0755, true);

        // write to a temporary file, then move: a half-written feed is never served
        $temporary = $path.'.tmp';

        $xml = new XMLWriter;
        $xml->openUri($temporary);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $xml->startElement('channel');
        $xml->writeElement('title', $this->config['title']);
        $xml->writeElement('link', config('app.url'));
        $xml->writeElement('description', $this->config['description']);

        $this->query()->chunkById(200, function ($products) use ($xml) {
            foreach ($products as $product) {
                $this->writeItem($xml, $product);
            }

            gc_collect_cycles();
        });

        $xml->endElement();   // channel
        $xml->endElement();   // rss
        $xml->endDocument();
        $xml->flush();

        rename($temporary, $path);
        app()->setLocale($previous);

        Log::info('facebook feed generated', ['items' => $this->written] + $this->skipped);

        return $path;
    }

    /* ------------------------------------------------------------------ query */

    protected function query()
    {
        $excluded = $this->excludedCategoryIds();

        return Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->whereNotNull('category_id')
            ->when($excluded, fn ($q) => $q->whereNotIn('category_id', $excluded))
            ->when($this->config['exclude_brands'], fn ($q) => $q
                ->whereDoesntHave('brand', fn ($b) => $b->whereIn('slug', $this->config['exclude_brands'])))
            ->withTranslation()
            ->with([
                'images',
                'brand',
                'category' => fn ($q) => $q->withTranslation()->with(['parent' => fn ($p) => $p->withTranslation()]),
            ]);
    }

    /** Excluded categories take their whole branch with them. */
    protected function excludedCategoryIds(): array
    {
        $ids = $this->config['exclude_categories'];

        $hidden = Category::where('in_feed', false)->pluck('id')->all();
        $ids = array_unique(array_merge($ids, $hidden));

        if (! $ids) {
            return [];
        }

        return Category::whereIn('id', $ids)->get()
            ->flatMap(fn (Category $c) => $c->descendantAndSelfIds())
            ->unique()->values()->all();
    }

    /* ------------------------------------------------------------------ one item */

    protected function writeItem(XMLWriter $xml, Product $product): void
    {
        $name = trim((string) $product->name);

        if ($name === '') {
            $this->skipped['no_name']++;

            return;
        }

        $price = (float) $product->price;
        $old = $product->old_price ? (float) $product->old_price : null;

        // the price a shopper pays is what the advert must show
        if ($price < $this->config['min_price']) {
            $this->skipped['price']++;

            return;
        }

        $image = $this->image($product);

        if (! $image) {
            $this->skipped['no_image']++;

            return;
        }

        $currency = $this->config['currency'];
        $brand = $product->brand?->name ?: '—';
        $category = $product->category;

        $xml->startElement('item');

        $xml->writeElement('g:id', (string) $product->id);
        $xml->writeElement('g:title', $this->clean(trim($brand.' '.$name), 150));
        $xml->writeElement('g:description', $this->description($product, $name));
        $xml->writeElement('g:link', route('product', $product->slug));
        $xml->writeElement('g:image_link', $image);

        foreach ($this->gallery($product) as $extra) {
            $xml->writeElement('g:additional_image_link', $extra);
        }

        $xml->writeElement('g:availability', $this->availability($product));
        $xml->writeElement('g:condition', 'new');
        $xml->writeElement('g:brand', $this->clean($brand, 70));

        // with a sale price Facebook expects the original in "price"
        $xml->writeElement('g:price', number_format($old ?: $price, 2, '.', '').' '.$currency);

        if ($old && $old > $price) {
            $xml->writeElement('g:sale_price', number_format($price, 2, '.', '').' '.$currency);
        }

        if ($sku = $product->sku) {
            $xml->writeElement('g:mpn', $sku);
        }

        if ($google = $category?->google_category_id ?? $category?->parent?->google_category_id) {
            $xml->writeElement('g:google_product_category', (string) $google);
        }

        if ($category) {
            $xml->writeElement('g:product_type', $this->clean($this->productType($category), 750));
        }

        $xml->writeElement('g:quantity_to_sell_on_facebook', (string) max(1, (int) $product->stock));

        /* custom labels: what the ad manager can actually segment on */
        if ($product->is_preorder) {
            $xml->writeElement('g:custom_label_0', 'preorder');
        } elseif ($price > $this->config['instalment_from']) {
            $monthly = (int) ceil($price / $this->config['instalment_months']);
            $xml->writeElement('g:custom_label_0', "თვეში {$monthly}₾-დან");
        }

        if ($old && $old > $price) {
            $xml->writeElement('g:custom_label_1', '-'.(int) round((1 - $price / $old) * 100).'%');
        }

        if ($parent = $category?->parent?->name ?? $category?->name) {
            $xml->writeElement('g:custom_label_2', $this->clean($parent, 100));
        }

        $xml->writeElement('g:custom_label_3', $this->priceBand($price));

        $xml->endElement();
        $xml->flush();

        $this->written++;
    }

    /* ------------------------------------------------------------------ fields */

    protected function description(Product $product, string $name): string
    {
        $text = trim(strip_tags((string) ($product->summary ?: $product->description)));

        return $this->clean($text !== '' ? $text : $name, 5000);
    }

    protected function availability(Product $product): string
    {
        return match (true) {
            $product->stock > 0 => 'in stock',
            (bool) $product->is_preorder => 'available for order',
            default => 'out of stock',
        };
    }

    protected function image(Product $product): ?string
    {
        $first = $product->images->sortBy('sort_order')->first();

        return url($first?->url());
    }

    /** @return array<int, string> */
    protected function gallery(Product $product): array
    {
        return $product->images->sortBy('sort_order')->skip(1)->take(10)
            ->map->url()->filter()->values()->all();
    }

    /** "ტექნიკა > ტელეფონები > სმარტფონები" — Facebook reads this as a hierarchy. */
    protected function productType(Category $category): string
    {
        return collect($category->ancestorsAndSelf())
            ->map(fn (Category $c) => $c->name)
            ->filter()
            ->implode(' > ');
    }

    /** A band is easier to target than a raw price. */
    protected function priceBand(float $price): string
    {
        return match (true) {
            $price < 200 => '0-200',
            $price < 500 => '200-500',
            $price < 1000 => '500-1000',
            $price < 2500 => '1000-2500',
            default => '2500+',
        };
    }

    protected function clean(string $value, int $limit): string
    {
        $value = preg_replace('/\s+/u', ' ', strip_tags($value));

        return mb_substr(trim($value), 0, $limit);
    }
}
