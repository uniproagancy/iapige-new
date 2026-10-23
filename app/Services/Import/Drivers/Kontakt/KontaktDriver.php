<?php

namespace App\Services\Import\Drivers\Kontakt;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

class KontaktDriver implements SupplierDriver
{
    protected KontaktClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new KontaktClient($supplier->config ?? []);
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
        $url = $data['url'] ?? null;

        if (! $url || ! str_starts_with($url, 'http')) {
            return null;
        }

        $page = $this->client->page($url);

        return $page && $page['name'] ? $this->toPayload($externalId, $row, $data, $page) : null;
    }

    protected function toPayload(string $model, SupplierStock $row, array $data, array $page): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        $cost = (float) $row->cost_price ?: (float) $page['price'];

        if ($cost <= 0) {
            return null;
        }

        $sale = isset($data['sale']) ? (float) $data['sale'] : 0.0;
        [$price, $old] = $sale > 0 && $sale < $cost ? [$sale, $cost] : [$cost, null];

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

            /* The article number the shop itself prints, when the page gives
               one; the price list code only when it does not. */
            sku: $page['sku'] ?: $this->supplier->code.'-'.$model,
            costPrice: $price,
            oldCostPrice: $old,
            stock: $stock,
            brandName: $page['brand'] ?? null,

            categoryName: $data['category'] ?? $page['category'] ?? null,
            translations: [$locale => [
                'name' => $this->rewrite($page['name']),
                'description' => $this->rewrite($page['description']),
            ]],
            specs: $specs,
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
