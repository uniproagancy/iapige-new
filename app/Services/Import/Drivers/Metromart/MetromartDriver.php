<?php

namespace App\Services\Import\Drivers\Metromart;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

class MetromartDriver implements SupplierDriver
{
    protected MetromartClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new MetromartClient($supplier->config ?? []);
    }

    public function ids(): iterable
    {
        return SupplierStock::where('supplier_id', $this->supplier->id)
            /* No ordering here: lazyById pages on the primary key, and a
               second sort makes it page over the wrong rows and skip some. */
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

        $url = $this->client->findUrl($externalId);

        if (! $url) {
            return null;
        }

        $page = $this->client->page($url);

        return $page ? $this->toPayload($externalId, $row, $page) : null;
    }

    protected function toPayload(string $model, SupplierStock $row, array $page): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        [$price, $old] = $this->prices($row, $page);

        if ($price <= 0) {
            return null;
        }

        $specs = array_map(fn ($spec) => [
            'name' => $spec['name'],
            'value' => $spec['value'],
            'locale' => $locale,
            'key' => false,

            'filterable' => false,
            'color' => null,
        ], $page['specs'] ?? []);

        $stock = $page['in_stock'] ? max(1, (int) $row->quantity) : 0;

        return new ProductPayload(
            externalId: $model,
            sku: $this->supplier->code.'-'.$model,
            costPrice: $price,
            oldCostPrice: $old,
            stock: $stock,
            brandName: $page['brand'] ?? null,

            categoryName: $row->data['category'] ?? $page['category'] ?? null,
            translations: [$locale => [
                'name' => $page['name'],
            ]],
            specs: $specs,
            images: $page['images'] ?? [],
        );
    }

    protected function prices(SupplierStock $row, array $page): array
    {
        $agreed = (float) $row->cost_price;

        if ($agreed > 0) {
            return [$agreed, null];
        }

        $uplift = 1 + (float) ($this->supplier->config['site_price_uplift'] ?? 0);

        $retail = (float) ($page['price'] ?? 0);
        $sale = (float) ($page['sale'] ?? 0);

        if ($sale > 0 && $sale < $retail) {
            return [round($sale * $uplift, 2), round($retail * $uplift, 2)];
        }

        $price = $sale > 0 ? $sale : $retail;

        return [round($price * $uplift, 2), null];
    }
}
