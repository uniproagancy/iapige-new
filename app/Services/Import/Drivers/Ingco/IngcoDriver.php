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

        /*
         * Cheap tools are not worth a listing here: they cost more to handle
         * and photograph than they return. The floor is a commercial decision,
         * so it lives in the supplier's own config.
         *
         * Compared against the figure we actually pay — the promotional price
         * in column B — and not the list price beside it. Reading the list
         * price instead let a tool through on a number nobody was charged:
         * B 105 with C 126 cleared a floor of 120 while costing 105.
         */
        [$price] = $this->prices($row);

        $floor = (float) ($this->supplier->config['min_price'] ?? 0);

        if ($floor > 0 && $price < $floor) {
            return null;
        }

        $url = $this->client->findUrl($externalId);

        if (! $url) {
            return null;   // the site does not carry it; nothing to describe
        }

        $page = $this->client->page($url);

        return $page ? $this->toPayload($externalId, $row, $page) : null;
    }

    /**
     * What we pay, and the figure to strike through.
     *
     * The price list carries two: the list price and the promotional one, and
     * the promotional one is what the supplier charges. One place, because the
     * floor in fetch() and the price on the product have to be the same number
     * — they were not, and a tool priced 105 cleared a floor of 120 on the
     * strength of the 126 printed next to it.
     *
     * @return array{0: float, 1: ?float}
     */
    protected function prices(SupplierStock $row): array
    {
        $list = (float) $row->cost_price;
        $sale = (float) ($row->data['sale'] ?? 0);

        return $sale > 0 && $sale < $list ? [$sale, $list] : [$list, null];
    }

    /**
     * How many of these we will actually offer.
     *
     * A handful left is not worth selling: the last two of a line are the ones
     * that turn into a cancelled order, and the price list is a day old by the
     * time anybody buys. Below the floor the product stays in the catalogue and
     * reads as unavailable, rather than disappearing — it comes back on its own
     * when the supplier restocks.
     *
     * The floor is a commercial decision, so it lives in the supplier's config
     * beside min_price. Zero or unset keeps every quantity.
     */
    protected function sellableStock(int $quantity): int
    {
        $floor = (int) ($this->supplier->config['min_stock'] ?? 0);

        return $quantity >= $floor ? $quantity : 0;
    }

    /* ------------------------------------------------------------------ mapping */

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
            // nothing is filterable on arrival; that is decided in the admin
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
