<?php

namespace App\Services\Import\Drivers\TanTan;

use App\Models\Supplier;
use App\Services\Import\ChecksImportSource;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use App\Support\Figure;

/*
 | TanTan Georgia — tools, garden and power equipment, from the shop's own
 | WooCommerce API. The Georgian product is the product; its English twin,
 | which WPML keeps as a second product, supplies the English text.
 */
class TanTanDriver implements ChecksImportSource, SupplierDriver
{
    protected TanTanClient $client;

    /* The brand comes as a product attribute, not in WooCommerce's brands. */
    protected const BRAND_ATTRIBUTES = ['ბრენდი', 'brand', 'Brand'];

    public function __construct(public Supplier $supplier)
    {
        $this->client = new TanTanClient(array_replace((array) config('services.tantan', []), $supplier->config ?? []));
    }

    /* A run starts from the shop as it is now, not from a copy kept for the
       last one; asking also proves the keys still work before any job is queued. */
    public function checkSource(): void
    {
        $this->client->all('ka', fresh: true);
        $this->client->all('en', fresh: true);
    }

    public function ids(): iterable
    {
        return array_keys($this->client->all('ka'));
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        $product = $this->client->all('ka')[$externalId] ?? $this->client->one($externalId);

        if (! $product) {
            return null;
        }

        $english = null;

        if ($twin = (string) ($product['translations']['en'] ?? '')) {
            $english = $this->client->all('en')[$twin] ?? $this->client->one($twin);
        }

        return $this->toPayload($externalId, $product, $english);
    }

    protected function toPayload(string $externalId, array $product, ?array $english): ?ProductPayload
    {
        [$price, $old] = $this->prices($product);

        if (trim($product['name']) === '' || $price <= 0) {
            return null;
        }

        $translations = ['ka' => $this->texts($product)];

        if ($english && trim($english['name']) !== '') {
            $translations['en'] = $this->texts($english);
        }

        return new ProductPayload(
            externalId: $externalId,
            sku: $this->supplier->code.'-'.($product['sku'] ?: $externalId),
            costPrice: $price,
            oldCostPrice: $old,
            stock: $this->stock($product),
            brandName: $this->brand($product),
            categoryName: $this->category($product),
            translations: $translations,
            specs: $this->specs($product),
            images: array_slice($product['images'], 0, (int) ($this->supplier->config['max_images'] ?? 8)),
            weight: $this->weight($product['weight']),
        );
    }

    /* "price" is what the shop charges today; on a sale "regular_price" is
       the figure it struck through. */
    protected function prices(array $product): array
    {
        $price = Figure::amount($product['price'], Figure::DOT);
        $regular = Figure::amount($product['regular_price'], Figure::DOT);

        if ($price <= 0) {
            $price = Figure::amount($product['sale_price'], Figure::DOT) ?: $regular;
        }

        return [$price, $product['on_sale'] && $regular > $price ? $regular : null];
    }

    /* The shop says only "in stock" or not; it keeps no count. A count it
       does keep is used as it stands. */
    protected function stock(array $product): int
    {
        if ($product['stock_status'] !== 'instock') {
            return 0;
        }

        if (is_numeric($product['stock_quantity'])) {
            return max(0, (int) $product['stock_quantity']);
        }

        return max(1, (int) ($this->supplier->config['stock_when_available'] ?? 5));
    }

    protected function texts(array $product): array
    {
        return [
            'name' => trim($product['name']),
            'summary' => trim(strip_tags($product['short_description'])) !== '' ? $product['short_description'] : null,
            'description' => trim(strip_tags($product['description'])) !== '' ? $product['description'] : null,
        ];
    }

    protected function brand(array $product): ?string
    {
        foreach ($product['attributes'] as $attribute) {
            if (in_array(trim($attribute['name']), self::BRAND_ATTRIBUTES, true) && filled($attribute['options'][0] ?? null)) {
                return trim((string) $attribute['options'][0]);
            }
        }

        return null;
    }

    /* A product is filed under a section and its sub-sections at once
       ("ელექტრო ხელსაწყოები" and "ელემენტზე"); the deepest is the one that
       says what it is. */
    protected function category(array $product): ?string
    {
        $tree = $this->client->categories();
        $best = null;
        $bestDepth = -1;

        foreach ($product['categories'] as $id) {
            $depth = 0;
            $at = $id;

            while (isset($tree[$at]) && $tree[$at]['parent'] && $depth < 10) {
                $at = $tree[$at]['parent'];
                $depth++;
            }

            if (isset($tree[$id]) && $depth > $bestDepth) {
                [$best, $bestDepth] = [$tree[$id]['name'], $depth];
            }
        }

        return $best;
    }

    /* Attributes other than the brand are specifications; today the shop
       sends none, and the description's "🔹 name: value" lines are read by
       the importer itself. */
    protected function specs(array $product): array
    {
        $specs = [];

        foreach ($product['attributes'] as $attribute) {
            $name = trim($attribute['name']);
            $value = trim(implode(', ', array_map('strval', $attribute['options'])));

            if ($name === '' || $value === '' || in_array($name, self::BRAND_ATTRIBUTES, true)) {
                continue;
            }

            $specs[] = ['name' => $name, 'value' => $value, 'locale' => 'ka', 'key' => false, 'filterable' => true, 'color' => null];
        }

        return $specs;
    }

    /* WooCommerce weighs in kilograms; a payload carries grams. */
    protected function weight(string $weight): ?int
    {
        $kg = Figure::amount($weight, Figure::DOT);

        return $kg > 0 ? (int) round($kg * 1000) : null;
    }
}
