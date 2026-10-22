<?php

namespace App\Services\Import\Drivers\MagiToys;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Support\Facades\Log;

/*
 | The price list carries a code, a quantity, a price and a link — the rest of
 | the product is on the page the link points at, so each row is a worklist
 | entry rather than a finished record.
 */
class MagiToysDriver implements SupplierDriver
{
    protected MagiToysClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new MagiToysClient($supplier->config ?? []);
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

        $data = (array) ($row->data ?? []);
        $url = trim((string) ($data['url'] ?? ''));

        if (! str_starts_with($url, 'http')) {
            Log::channel('import')->info('magitoys row has no link to follow', ['code' => $externalId]);

            return null;
        }

        $page = $this->client->product($url);

        if (! $page) {
            return null;
        }

        $cost = $this->cost($row, $page);

        if ($cost <= 0) {
            Log::channel('import')->info('magitoys row has no price', ['code' => $externalId]);

            return null;
        }

        return new ProductPayload(
            externalId: $externalId,
            sku: $this->sku($externalId, $page),
            costPrice: $cost,
            oldCostPrice: null,
            stock: $this->stockFor($row, $page),
            brandName: $page['brand'] ?: ($data['brand'] ?? null),
            categoryName: $data['category'] ?? $page['category'],
            translations: $this->translations($page),
            specs: $this->specs($page),
            images: $page['images'],
            weight: $page['weight'],
        );
    }

    /* The same product reaches us under two spellings of its address, so the
       shop keys it by the number the source itself gives it. */
    protected function sku(string $externalId, array $page): string
    {
        $id = $page['id'] ?: $externalId;

        return strtoupper($this->supplier->code).'-'.$id;
    }

    protected function cost(SupplierStock $row, array $page): float
    {
        $cost = (float) $row->cost_price;

        return $cost > 0 ? $cost : (float) ($page['price'] ?? 0);
    }

    /* The sheet is the stock record; the page only says whether the source
       still sells it at all. */
    protected function stockFor(SupplierStock $row, array $page): int
    {
        if (! $page['in_stock']) {
            return 0;
        }

        $quantity = (int) $row->quantity;

        if ($quantity > 0) {
            return $quantity;
        }

        return (int) ($this->supplier->config['default_stock'] ?? 0);
    }

    protected function translations(array $page): array
    {
        $locale = (string) ($this->supplier->config['locale'] ?? 'ka');

        return [
            $locale => array_filter([
                'name' => $page['name'],
                'summary' => $page['summary'],
            ]),
        ];
    }

    protected function specs(array $page): array
    {
        $locale = (string) ($this->supplier->config['locale'] ?? 'ka');
        $keys = (array) ($this->supplier->config['key_specs'] ?? []);

        $specs = [];

        foreach ($page['specs'] as $spec) {
            $specs[] = [
                'name' => $spec['name'],
                'value' => $spec['value'],
                'locale' => $locale,
                'key' => in_array($spec['name'], $keys, true),
                'filterable' => true,
                'color' => null,
            ];
        }

        return $specs;
    }
}
