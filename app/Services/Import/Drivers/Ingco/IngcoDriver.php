<?php

namespace App\Services\Import\Drivers\Ingco;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

class IngcoDriver implements SupplierDriver
{
    protected IngcoClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new IngcoClient($supplier->config ?? []);
    }

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

        [$price] = $this->prices($row);

        $floor = (float) ($this->supplier->config['min_price'] ?? 0);

        if ($floor > 0 && $price < $floor) {
            return null;
        }

        $url = $this->client->findUrl($externalId);

        if (! $url) {
            return null;
        }

        $page = $this->client->page($url);

        return $page ? $this->toPayload($externalId, $row, $page) : null;
    }

    protected function prices(SupplierStock $row): array
    {
        $list = (float) $row->cost_price;
        $sale = (float) ($row->data['sale'] ?? 0);

        return $sale > 0 && $sale < $list ? [$sale, $list] : [$list, null];
    }

    protected function sellableStock(int $quantity): int
    {
        $floor = (int) ($this->supplier->config['min_stock'] ?? 0);

        return $quantity >= $floor ? $quantity : 0;
    }

    protected function toPayload(string $model, SupplierStock $row, array $page): ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';
        $data = $row->data ?? [];

        [$price, $old] = $this->prices($row);

        $specs = array_map(fn ($spec) => [
            'name' => $spec['name'],
            'value' => $spec['value'],
            'locale' => $locale,
            'key' => false,

            'filterable' => false,
            'color' => null,
        ], $page['specs'] ?? []);

        return new ProductPayload(
            externalId: $model,
            sku: $this->supplier->code.'-'.$model,
            costPrice: $price,
            oldCostPrice: $old,
            stock: ($page['in_stock'] ?? true) ? $this->sellableStock((int) $row->quantity) : 0,
            brandName: $this->supplier->config['brand'] ?? 'INGCO',

            categoryName: $data['category'] ?? $page['category'] ?? null,
            translations: [$locale => [
                'name' => $page['name'],
                'description' => $page['description'] ?? null,
            ]],
            specs: $specs,
            images: $page['images'] ?? [],
        );
    }
}
