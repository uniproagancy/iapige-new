<?php

namespace App\Services\Import\Drivers\Ingco;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

/**
 * INGCO.
 *
 * Two sources, each authoritative over its own half: the spreadsheet decides
 * what exists and what it costs, the website supplies the name, photographs
 * and specifications. A model missing from either is skipped rather than
 * half-imported.
 */
class IngcoDriver implements SupplierDriver
{
    protected IngcoClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new IngcoClient($supplier->config ?? []);
    }

    /** Every model code in the last uploaded price list. */
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

        $cost = (float) $row->cost_price;

        /*
         * Cheap tools are not worth a listing here: they cost more to handle
         * and photograph than they return. The floor is a commercial decision,
         * so it lives in the supplier's own config.
         */
        $floor = (float) ($this->supplier->config['min_price'] ?? 0);

        if ($floor > 0 && $cost < $floor) {
            return null;
        }

        $url = $this->client->findUrl($externalId);

        if (! $url) {
            return null;   // the site does not carry it; nothing to describe
        }

        $page = $this->client->page($url);

        return $page ? $this->toPayload($externalId, $row, $page) : null;
    }

    /* ------------------------------------------------------------------ mapping */

    protected function toPayload(string $model, SupplierStock $row, array $page): ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';
        $data = $row->data ?? [];

        // the list price and the promotional one; the lower is what we pay
        $cost = (float) $row->cost_price;
        $sale = isset($data['sale']) ? (float) $data['sale'] : 0.0;

        [$price, $old] = $sale > 0 && $sale < $cost ? [$sale, $cost] : [$cost, null];

        $specs = array_map(fn ($spec) => [
            'name' => $spec['name'],
            'value' => $spec['value'],
            'locale' => $locale,
            'key' => false,
            // nothing is filterable on arrival; that is decided in the admin
            'filterable' => false,
            'color' => null,
        ], $page['specs'] ?? []);

        return new ProductPayload(
            externalId: $model,
            sku: $this->supplier->code.'-'.$model,
            costPrice: $price,
            oldCostPrice: $old,
            // the price list carries no quantity, so presence on the site decides
            stock: ($page['in_stock'] ?? true) ? max(1, (int) $row->quantity) : 0,
            brandName: $this->supplier->config['brand'] ?? 'INGCO',
            categoryName: $data['category'] ?? null,
            translations: [$locale => [
                'name' => $page['name'],
                'description' => $page['description'] ?? null,
            ]],
            specs: $specs,
            images: $page['images'] ?? [],
        );
    }
}
