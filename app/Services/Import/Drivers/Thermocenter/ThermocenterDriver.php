<?php

namespace App\Services\Import\Drivers\Thermocenter;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

class ThermocenterDriver implements SupplierDriver
{
    protected ThermocenterClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new ThermocenterClient($supplier->config ?? []);
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

        $data = $row->data ?? [];
        $url = $data['url'] ?? null;

        if (! $url || ! str_starts_with($url, 'http')) {
            return null;
        }

        $page = $this->client->page($url);

        return $page && $page['name'] ? $this->toPayload($externalId, $row, $data, $page) : null;
    }

    protected function toPayload(string $externalId, SupplierStock $row, array $data, array $page): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        $cost = (float) $row->cost_price ?: (float) $page['price'];

        if ($cost <= 0) {
            return null;
        }

        $stock = $page['in_stock'] ? max(0, (int) $row->quantity) : 0;

        return new ProductPayload(
            externalId: $externalId,

            sku: $page['sku'] ? $this->supplier->code.'-'.$page['sku'] : $this->supplier->code.'-'.$externalId,
            costPrice: $cost,
            oldCostPrice: $page['old_price'],
            stock: $stock,
            brandName: $page['brand'] ?? null,

            categoryName: $data['category'] ?? $page['category'] ?? null,
            translations: [$locale => [
                'name' => $this->rewrite($page['name']),
                'description' => $this->rewrite($page['description']),
            ]],
            images: $page['images'] ?? [],
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

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
