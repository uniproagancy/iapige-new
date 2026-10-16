<?php

namespace App\Services\Import\Drivers\Alta;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\HasStockFeed;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

class AltaDriver implements HasStockFeed, SupplierDriver
{
    protected AltaClient $client;

    protected AltaStockClient $stock;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new AltaClient($supplier->config ?? []);
        $this->stock = new AltaStockClient($supplier->config ?? []);
    }

    public function refreshStock(): int
    {
        $rows = $this->stock->items();
        $now = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            SupplierStock::upsert(
                array_map(fn ($row) => $row + [
                    'supplier_id' => $this->supplier->id,
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk),
                ['supplier_id', 'external_id'],
                ['quantity', 'synced_at', 'updated_at'],
            );
        }

        SupplierStock::where('supplier_id', $this->supplier->id)
            ->where('synced_at', '<', $now)
            ->update(['quantity' => 0, 'synced_at' => $now]);

        return count($rows);
    }

    public function ids(): iterable
    {
        $min = (int) ($this->supplier->config['min_quantity'] ?? 2);

        return SupplierStock::where('supplier_id', $this->supplier->id)
            ->where('quantity', '>=', $min)
            ->orderBy('external_id')
            ->lazyById(500)
            ->map(fn (SupplierStock $s) => $s->external_id);
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        $route = $this->client->route($externalId);

        if (! $route) {
            return null;
        }

        $data = $this->client->page($route);

        return $data ? $this->toPayload($externalId, $data) : null;
    }

    protected function toPayload(string $externalId, array $data): ?ProductPayload
    {
        $p = $data['product'];
        $locale = $this->supplier->config['locale'] ?? 'ka';

        $specs = [];

        foreach ($p['specificationGroup'] ?? [] as $group) {
            foreach ($group['specifications'] ?? [] as $spec) {
                if (! ($spec['isInProductPage'] ?? true)) {
                    continue;
                }

                $specs[] = [
                    'name' => trim($spec['specificationName'] ?? ''),
                    'value' => trim($spec['specificationMeaning'] ?? ''),
                    'locale' => $locale,
                    'group' => trim($group['groupName'] ?? ''),
                    'key' => (bool) ($spec['isMainSpecification'] ?? false),

                    'filterable' => filled($spec['specificationLinkedUrl'] ?? null),
                    'color' => ($spec['isColor'] ?? false) ? ($spec['colorValue'] ?? null) : null,
                ];
            }
        }

        $specs = array_values(array_filter(
            $specs,
            fn ($s) => $s['name'] !== '' && $s['value'] !== '' && $s['value'] !== '-',
        ));

        $quantity = (int) SupplierStock::where('supplier_id', $this->supplier->id)
            ->where('external_id', $externalId)
            ->value('quantity');

        return new ProductPayload(
            externalId: $externalId,
            sku: $this->supplier->code.'-'.$externalId,

            costPrice: (float) ($p['previousPrice'] ?? $p['price'] ?? 0),
            oldCostPrice: isset($p['previousPrice']) ? (float) $p['price'] : null,
            stock: $quantity,
            brandName: $this->brand($p),
            categoryName: $p['categoryName'] ?? null,
            translations: [$locale => [
                'name' => $p['name'] ?? null,
                'description' => $p['description'] ?? null,
            ]],
            specs: $specs,
            images: array_values(array_filter($p['images'] ?? [])),
        );
    }

    protected function brand(array $product): ?string
    {
        foreach ($product['specificationGroup'] ?? [] as $group) {
            foreach ($group['specifications'] ?? [] as $spec) {
                if (in_array($spec['specificationName'] ?? '', ['Brand', 'ბრენდი', 'Бренд'], true)) {
                    return $spec['specificationMeaning'] ?? null;
                }
            }
        }

        return $product['brandName'] ?? null;
    }
}
