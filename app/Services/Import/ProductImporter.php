<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductSpec;
use App\Models\ProductSpecTranslation;
use App\Models\Supplier;
use App\Support\Slug;
use App\Support\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductImporter
{
    public function __construct(
        protected TaxonomyResolver $taxonomy,
        protected ImageDownloader $images,
    ) {}

    /*
     | $insist is for a product an operator picked out by hand: it arrives
     | even when nothing of it is in stock, because somebody asked for this
     | one in particular rather than for whatever a price list holds.
     */
    public function import(Supplier $supplier, ProductPayload $payload, bool $insist = false): ?Product
    {
        $payload = $this->repair($payload);

        [$product, $isNew] = $this->write($supplier, $payload, $insist);

        $wanted = min(count($payload->images), ImageDownloader::MAX_IMAGES);

        if ($product && $wanted > 0 && ($isNew || $product->images()->count() < $wanted)) {
            $this->images->sync($product, $payload->images);
        }

        if ($product && $isNew && ! $this->keep($supplier, $product)) {
            return null;
        }

        if ($product) {
            $this->publish($supplier, $product);
        }

        return $product;
    }

    protected function publish(Supplier $supplier, Product $product): void
    {
        $wanted = $supplier->config['publish_on_import'] ?? config('shop.publish_on_import', false);

        if (! $wanted
            || $product->status !== Product::STATUS_DRAFT
            || $product->published_at !== null) {
            return;
        }

        if (! Product::readyToPublish()->whereKey($product->id)->exists()) {
            return;
        }

        $product->update([
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now(),
        ]);

        $this->log('published on arrival', $supplier, null, [
            'product' => $product->id,
            'sku' => $product->sku,
        ]);
    }

    protected function repair(ProductPayload $payload): ProductPayload
    {
        return new ProductPayload(
            externalId: $payload->externalId,
            sku: $payload->sku,
            costPrice: $payload->costPrice,
            oldCostPrice: $payload->oldCostPrice,
            stock: $payload->stock,
            brandName: Text::utf8($payload->brandName),
            categoryName: Text::utf8($payload->categoryName),
            translations: Text::utf8All($payload->translations),
            specs: Text::utf8All($payload->specs),
            images: $payload->images,
            weight: $payload->weight,
            variantGroup: $payload->variantGroup,
            isPreorder: $payload->isPreorder,
            releaseDate: $payload->releaseDate,
        );
    }

    protected function worthAdding(Supplier $supplier, ProductPayload $payload, bool $insist = false): bool
    {
        /* The floor below still holds -- it is about what is worth selling,
           not about what happens to be on the shelf today. */
        $required = ! $insist && ($supplier->config['require_stock'] ?? config('shop.require_stock', false));

        if ($required && $payload->stock <= 0) {
            Log::channel('import')->info('skipped: nothing in stock', [
                'supplier' => $supplier->code,
                'external' => $payload->externalId,
                'sku' => $payload->sku,
            ]);

            return false;
        }

        $floor = (float) ($supplier->config['min_cost'] ?? 0);

        if ($floor > 0 && $payload->costPrice < $floor) {
            Log::channel('import')->info('skipped: below the supplier floor', [
                'supplier' => $supplier->code,
                'external' => $payload->externalId,
                'sku' => $payload->sku,
                'cost' => $payload->costPrice,
                'floor' => $floor,
            ]);

            return false;
        }

        return true;
    }

    protected function keep(Supplier $supplier, Product $product): bool
    {
        $required = $supplier->config['require_image'] ?? config('shop.require_image', false);

        if (! $required || $product->images()->exists()) {
            return true;
        }

        Log::channel('import')->info('dropped: no photograph', [
            'supplier' => $supplier->code,
            'sku' => $product->sku,
            'product' => $product->id,
        ]);

        $product->forceDelete();

        return false;
    }

    protected function write(Supplier $supplier, ProductPayload $payload, bool $insist = false): array
    {
        try {
            return $this->writeInTransaction($supplier, $payload, $insist);
        } catch (\Throwable $e) {

            $this->taxonomy->forget();

            throw $e;
        }
    }

    protected function writeInTransaction(Supplier $supplier, ProductPayload $payload, bool $insist = false): array
    {
        return DB::transaction(function () use ($supplier, $payload, $insist) {
            $product = $this->find($supplier, $payload);
            $isNew = ! $product;

            if ($isNew && ! $this->worthAdding($supplier, $payload, $insist)) {
                return [null, false];
            }

            $product ??= new Product([
                'sku' => $payload->sku,
                'status' => Product::STATUS_DRAFT,
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
                $product->old_price = $this->strikeThrough($best, (float) $product->price);
            }
        }

        $product->save();
    }

    /* A price with a lower one struck through above it reads as nonsense, and
       a supplier's "old price" is not always the higher of the two. */
    protected function strikeThrough(ProductOffer $offer, float $price): ?float
    {
        if (! $offer->old_cost_price) {
            return null;
        }

        $was = $offer->supplier->markup((float) $offer->old_cost_price);

        return $was > $price ? $was : null;
    }

    protected function fillTaxonomy(Product $product, Supplier $supplier, ProductPayload $payload): void
    {

        $category = $this->taxonomy->category($supplier, $payload->categoryName);
        $brand = $this->taxonomy->brand($payload->brandName);

        if ($product->taxonomy_lock) {
            return;
        }

        $product->category_id = $category?->id ?? $product->category_id;
        $product->brand_id = $brand?->id ?? $product->brand_id;
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
                'name' => $this->fit($fields['name'], 255),

                'slug' => $existing?->slug ?: $this->slugFor($product, $fields['name'], $locale),
                'summary' => $this->fit($fields['summary'] ?? $existing?->summary, 255),
                'description' => $fields['description'] ?? $existing?->description,
            ];
        }

        if (! $rows) {
            $this->log('skipped: no usable name in any language', null, $payload);

            return;
        }

        $product->saveTranslations($rows);
    }

    /* A supplier that hands over a whole description where a one-line summary
       belongs would otherwise take the whole import down on a column width. */
    protected function fit(?string $value, int $max): ?string
    {
        if ($value === null || mb_strlen($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max - 1)).'…';
    }

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

            /* Two supplier keys can be mapped onto the same attribute. Keep the
               first one rather than letting the later one silently win. */
            if (isset($byAttribute[$attribute->id])) {
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

            if ($value = $this->taxonomy->attributeValue($attribute, $rows->all())) {
                $valueIds[] = $value->id;
            }
        }

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

        $product->attributeValues()->sync(array_unique($valueIds));
    }

    protected function log(string $message, ?Supplier $supplier, ?ProductPayload $payload, array $extra = []): void
    {
        Log::channel('import')->info($message, array_merge(array_filter([
            'supplier' => $supplier?->code,
            'external' => $payload?->externalId,
            'sku' => $payload?->sku,
            'name' => $payload ? ($payload->translations[array_key_first($payload->translations)]['name'] ?? null) : null,
        ]), $extra));
    }
}
