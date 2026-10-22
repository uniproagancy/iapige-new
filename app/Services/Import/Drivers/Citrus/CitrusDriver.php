<?php

namespace App\Services\Import\Drivers\Citrus;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

class CitrusDriver implements SupplierDriver
{
    protected CitrusClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new CitrusClient($supplier->config ?? []);
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

        $slug = $row->data['slug'] ?? null;

        if (! $slug) {
            return null;
        }

        $found = $this->client->bySlug($slug);

        return $found ? $this->toPayload($externalId, $row, $found) : null;
    }

    protected function toPayload(string $externalId, SupplierStock $row, array $found): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        if ($found['name'] === '' || $found['price'] <= 0) {
            return null;
        }

        $specs = array_map(fn (array $spec) => [
            'name' => $spec['name'],
            'value' => $spec['value'],
            'locale' => $locale,
            'key' => false,

            'filterable' => $spec['filterable'],
            'color' => null,
        ], $found['attributes']);

        if ($found['warranty']) {
            $specs[] = [
                'name' => __('product.warranty'),
                'value' => $found['warranty'],
                'locale' => $locale,
                'key' => false,
                'filterable' => false,
                'color' => null,
            ];
        }

        $stock = $found['in_stock'] ? max(0, (int) $found['stock']) : 0;

        return new ProductPayload(
            externalId: $externalId,

            sku: $this->supplier->code.'-'.($found['sku'] ?: $found['id']),
            costPrice: $found['price'],
            oldCostPrice: $found['old_price'],
            stock: $stock,
            brandName: $found['brand'],
            categoryName: $row->data['category'] ?? $found['category'],
            translations: [$locale => [
                'name' => $this->rewrite($found['name']),
                'summary' => $this->rewrite($found['summary']),
                'description' => $this->rewrite($found['description']),
            ]],
            specs: $specs,
            images: $found['images'],
            weight: $found['weight'],
        );
    }

    protected function rewrite(?string $text): ?string
    {
        if (! $text) {
            return $text;
        }

        foreach ((array) ($this->supplier->config['replace'] ?? []) as $from => $to) {
            $text = preg_replace('/'.preg_quote($from, '/').'/iu', $to, $text);
        }

        return trim(preg_replace('/[ \t]+/u', ' ', $text));
    }
}
