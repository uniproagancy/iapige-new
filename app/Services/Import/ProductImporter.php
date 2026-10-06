<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductSpec;
use App\Models\ProductSpecTranslation;
use App\Models\Supplier;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes a ProductPayload into the catalogue. One class for every supplier —
 * drivers only fetch and normalise, this decides what the shop actually sells.
 *
 * Everything it does or refuses to do goes to the "import" log channel, so a
 * run of 100 000 ids never drowns the application log.
 */
class ProductImporter
{
    public function __construct(
        protected TaxonomyResolver $taxonomy,
        protected ImageDownloader $images,
    ) {}

    public function import(Supplier $supplier, ProductPayload $payload): ?Product
    {
        [$product, $isNew] = $this->write($supplier, $payload);

        /*
         * Photographs are downloaded after the transaction has closed, never
         * inside it. Eight images from a slow CDN are minutes of waiting, and
         * doing that with the transaction open held row locks on products and
         * product_offers for the whole time — long enough for other import
         * workers to pile up behind it and time out.
         */
        /*
         * A later run retries a gallery that never arrived.
         *
         * Only new products used to be fetched, so a product whose photographs
         * all timed out on its first import stayed empty for good — no amount of
         * re-importing would ask for them again. The images table answers that
         * in one EXISTS, and only for products we are not creating.
         */
        if ($product && $payload->images && ($isNew || ! $product->images()->exists())) {
            $this->images->sync($product, $payload->images);
        }

        return $product;
    }

    /**
     * Everything that belongs in one transaction, and nothing that does not.
     *
     * @return array{0: ?Product, 1: bool}
     */
    protected function write(Supplier $supplier, ProductPayload $payload): array
    {
        return DB::transaction(function () use ($supplier, $payload) {
            $product = $this->find($supplier, $payload);
            $isNew = ! $product;

            $product ??= new Product([
                'sku' => $payload->sku,
                'status' => Product::STATUS_DRAFT,   // a human publishes it
                'price' => $supplier->markup($payload->costPrice),
                'stock' => $payload->stock,
            ]);

            $this->fillTaxonomy($product, $supplier, $payload);

            $product->weight ??= $payload->weight;
            $product->is_preorder = $payload->isPreorder;
            $product->release_date = $payload->releaseDate;

            if ($payload->variantGroup) {
                $product->variant_group = $payload->variantGroup;
            }

            $product->synced_at = now();
            $product->save();

            ProductOffer::updateOrCreate(
                ['supplier_id' => $supplier->id, 'external_id' => $payload->externalId],
                [
                    'product_id' => $product->id,
                    'cost_price' => $payload->costPrice,
                    'old_cost_price' => $payload->oldCostPrice,
                    'stock' => $payload->stock,
                    // kept so a mapping made later can be applied without a re-import
                    'external_category' => $payload->categoryName,
                    'external_brand' => $payload->brandName,
                    'synced_at' => now(),
                ],
            );

            $this->refreshFromOffers($product);
            $this->syncTranslations($product, $payload);
            $this->syncSpecs($product, $supplier, $payload);

            if (! $product->category_id) {
                $this->log('imported without a category', $supplier, $payload, [
                    'product' => $product->id,
                    'external_name' => $payload->categoryName,
                ]);
            }

            $this->log($isNew ? 'imported (new)' : 'updated', $supplier, $payload, [
                'product' => $product->id,
                'stock' => $payload->stock,
                'price' => $product->price,
            ]);

            return [$product, $isNew];
        });
    }

    /**
     * Find the product this payload belongs to.
     *
     * The offer is the reliable answer. The sku is only a fallback for matching
     * the SAME product across DIFFERENT suppliers — within one supplier two
     * colour variants often share a barcode, and merging them on that would put
     * one variant's photos on the other.
     */
    protected function find(Supplier $supplier, ProductPayload $payload): ?Product
    {
        $offer = ProductOffer::where('supplier_id', $supplier->id)
            ->where('external_id', $payload->externalId)
            ->first();

        if ($offer?->product) {
            return $offer->product;
        }

        return Product::where('sku', $payload->sku)
            ->whereDoesntHave('offers', fn ($q) => $q
                ->where('supplier_id', $supplier->id)
                ->where('external_id', '!=', $payload->externalId))
            ->first();
    }

    /**
     * The product sells at the cheapest offer that is actually in stock;
     * the stock we show is everything our suppliers hold together.
     */
    public function refreshFromOffers(Product $product): void
    {
        $offers = $product->offers()->with('supplier')->get();

        $best = $offers->sortBy([
            ['cost_price', 'asc'],
            fn ($a, $b) => $a->supplier->priority <=> $b->supplier->priority,
        ])->first();

        $product->stock = (int) $offers->sum('stock');

        if ($best) {
            $product->cost_price = $best->cost_price;

            if (! $product->price_lock) {
                $product->price = $best->supplier->markup((float) $best->cost_price);
                $product->old_price = $best->old_cost_price
                    ? $best->supplier->markup((float) $best->old_cost_price)
                    : null;
            }
        }

        $product->save();
    }

    protected function fillTaxonomy(Product $product, Supplier $supplier, ProductPayload $payload): void
    {
        if ($product->taxonomy_lock) {
            return;
        }

        $product->category_id = $this->taxonomy->category($supplier, $payload->categoryName)?->id
            ?? $product->category_id;

        $product->brand_id = $this->taxonomy->brand($payload->brandName)?->id
            ?? $product->brand_id;
    }

    protected function syncTranslations(Product $product, ProductPayload $payload): void
    {
        $rows = [];

        foreach ($payload->translations as $locale => $fields) {
            if (empty($fields['name'])) {
                continue;
            }

            $existing = $product->translate($locale, false);

            $rows[$locale] = [
                'name' => $fields['name'],
                // a published slug is never regenerated: changing it breaks links
                'slug' => $existing?->slug ?: $this->slugFor($product, $fields['name'], $locale),
                'summary' => $fields['summary'] ?? $existing?->summary,
                'description' => $fields['description'] ?? $existing?->description,
            ];
        }

        if (! $rows) {
            $this->log('skipped: no usable name in any language', null, $payload);

            return;
        }

        $product->saveTranslations($rows);
    }

    /**
     * The category word leads the slug when its category asks for it, because
     * that is how the title reads too — and the id keeps it unique whatever
     * two products happen to be called.
     */
    protected function slugFor(Product $product, string $name, string $locale): string
    {
        $prefix = '';
        $category = $product->category;

        if ($category?->prefix_title) {
            $translation = $category->translate($locale, false);
            $prefix = (string) ($translation?->title_prefix ?: $translation?->name);
        }

        return Slug::make(trim($prefix.' '.$name)).'-'.$product->id;
    }

    /**
     * Specs do double duty: they render on the product page and they feed the
     * catalog filters, so a spec on a filterable attribute also becomes an
     * attribute_value link.
     */
    protected function syncSpecs(Product $product, Supplier $supplier, ProductPayload $payload): void
    {
        if (! $payload->specs) {
            return;
        }

        $valueIds = [];
        $order = 0;
        $specRows = [];
        $byAttribute = [];
        $now = now();

        foreach (collect($payload->specs)->groupBy('name') as $name => $rows) {
            $filterable = (bool) ($rows->first()['filterable'] ?? false);
            $attribute = $this->taxonomy->attribute($supplier, (string) $name, $filterable);

            if (! $attribute) {
                continue;
            }

            $specRows[] = [
                'product_id' => $product->id,
                'attribute_id' => $attribute->id,
                'is_key' => (bool) ($rows->first()['key'] ?? false),
                'sort_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $byAttribute[$attribute->id] = $rows;

            // a value the resolver rejected ("-", empty) must not become a filter
            if ($value = $this->taxonomy->attributeValue($attribute, $rows->all())) {
                $valueIds[] = $value->id;
            }
        }

        /*
         * Written in bulk rather than row by row.
         *
         * A product with fifteen specs in two languages used to cost forty-five
         * statements here — one lookup and one write per spec, then one more
         * per spec per language. Both tables carry the unique key an upsert
         * needs, so the whole block is three.
         */
        if ($specRows) {
            ProductSpec::upsert($specRows, ['product_id', 'attribute_id'], ['is_key', 'sort_order', 'updated_at']);

            $specIds = ProductSpec::where('product_id', $product->id)->pluck('id', 'attribute_id');

            $translations = [];

            foreach ($byAttribute as $attributeId => $rows) {
                $specId = $specIds[$attributeId] ?? null;

                if (! $specId) {
                    continue;
                }

                foreach ($rows as $row) {
                    if (! filled($row['value'] ?? null)) {
                        continue;
                    }

                    $translations[] = [
                        'product_spec_id' => $specId,
                        'locale' => $row['locale'],
                        'value' => $row['value'],
                    ];
                }
            }

            if ($translations) {
                ProductSpecTranslation::upsert($translations, ['product_spec_id', 'locale'], ['value']);
            }
        }

        // replace the links wholesale, so a changed spec leaves no stale filter behind
        $product->attributeValues()->sync(array_unique($valueIds));
    }

    /** One place for import logging, on its own channel. */
    protected function log(string $message, ?Supplier $supplier, ProductPayload $payload, array $extra = []): void
    {
        Log::channel('import')->info($message, array_merge([
            'supplier' => $supplier?->code,
            'external' => $payload->externalId,
            'sku' => $payload->sku,
            'name' => $payload->translations[array_key_first($payload->translations)]['name'] ?? null,
        ], $extra));
    }
}
