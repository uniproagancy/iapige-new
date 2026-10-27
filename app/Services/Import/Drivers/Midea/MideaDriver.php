<?php

namespace App\Services\Import\Drivers\Midea;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use App\Support\Figure;

class MideaDriver implements SupplierDriver
{
    protected MideaClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new MideaClient($supplier->config ?? []);
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

        $data = $row->data ?? [];

        foreach ([$data['site_model'] ?? '', $data['model'] ?? ''] as $model) {
            $model = trim((string) $model);

            if ($model === '') {
                continue;
            }

            if ($found = $this->client->searchByName($model)) {
                return $this->toPayload($externalId, $row, $data, $found);
            }
        }

        return null;
    }

    protected function toPayload(string $barcode, SupplierStock $row, array $data, array $found): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        if ($found['name'] === '') {
            return null;
        }

        [$price, $old] = $this->prices($found, $data, $row);

        if ($price <= 0) {
            return null;
        }

        $specs = [];

        if (! empty($data['warranty'])) {
            $specs[] = [
                'name' => __('product.warranty'),
                'value' => $data['warranty'],
                'locale' => $locale,
                'key' => false,
                'filterable' => false,
                'color' => null,
            ];
        }

        $stock = $found['in_stock'] ? max(0, (int) $row->quantity) : 0;

        return new ProductPayload(
            externalId: $barcode,

            sku: $this->supplier->code.'-'.$barcode,
            costPrice: $price,
            oldCostPrice: $old,
            stock: $stock,
            brandName: $found['brand'] ?? 'Midea',
            categoryName: $data['category'] ?? null,
            translations: [$locale => [
                'name' => $found['name'],
                'summary' => $data['description'] ?? null,
                'description' => $found['description'] ?? $data['description'] ?? null,
            ]],
            specs: $specs,
            images: $found['images'] ?? [],
            weight: $found['weight'] ?? null,
        );
    }

    protected function prices(array $found, array $data, SupplierStock $row): array
    {
        if (($found['price'] ?? 0) > 0) {
            return $found['sale_price']
                ? [(float) $found['sale_price'], (float) $found['price']]
                : [(float) $found['price'], null];
        }

        $dealer = $this->money($data['cost'] ?? null) ?? (float) $row->cost_price;
        $retailSale = $this->money($data['retail_sale'] ?? null);
        $retail = $this->money($data['retail'] ?? null);

        if (($this->supplier->config['price_from'] ?? 'dealer') === 'retail') {
            return [$retailSale ?: $retail ?: $dealer, $retailSale && $retail > $retailSale ? $retail : null];
        }

        return [$dealer, null];
    }

    protected function money(?string $text): ?float
    {
        return Figure::money($text);
    }
}
