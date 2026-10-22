<?php

namespace App\Services\Import\Drivers\Alneo;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

class AlneoDriver implements SupplierDriver
{
    protected AlneoClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new AlneoClient($supplier->config ?? []);
    }

    public function ids(): iterable
    {

        $this->client->index(fresh: true);

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

        $url = $this->urlFor($externalId);

        if (! $url) {
            return null;
        }

        $page = $this->client->page($url);

        return $page ? $this->toPayload($externalId, $row, $page) : null;
    }

    protected function urlFor(string $code): ?string
    {
        $index = $this->client->index();

        if (isset($index[$code])) {
            return $index[$code];
        }

        $trimmed = ltrim($code, '0');

        foreach ($index as $sku => $url) {
            if (ltrim((string) $sku, '0') === $trimmed) {
                return $url;
            }
        }

        return null;
    }

    protected function toPayload(string $code, SupplierStock $row, array $page): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';
        $name = $page['name'] ?? '';

        if ($name === '') {
            return null;
        }

        $data = $row->data ?? [];

        [$price, $old] = $this->prices($row, $data, $page);

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

        return new ProductPayload(
            externalId: $code,
            sku: $this->supplier->code.'-'.$code,
            costPrice: $price,
            oldCostPrice: $old > $price ? $old : null,
            stock: (int) $row->quantity,
            brandName: $this->supplier->config['brand'] ?? null,

            categoryName: $data['category'] ?? $page['category'] ?? null,
            translations: [$locale => [
                'name' => $name,
                'description' => $page['description'] ?? null,
            ]],
            specs: $specs,
            images: $page['images'] ?? [],
        );
    }

    protected function prices(SupplierStock $row, array $data, array $page): array
    {
        $cost = (float) $row->cost_price;

        if ($cost > 0) {
            $sale = isset($data['sale']) ? (float) $data['sale'] : 0.0;

            return $sale > 0 && $sale < $cost ? [$sale, $cost] : [$cost, 0.0];
        }

        $uplift = (float) ($this->supplier->config['site_price_uplift'] ?? 0);

        $price = (float) ($page['price'] ?? 0);
        $old = (float) ($page['old'] ?? 0);

        return [
            $price > 0 ? $price + $uplift : 0.0,
            $old > 0 ? $old + $uplift : 0.0,
        ];
    }
}
