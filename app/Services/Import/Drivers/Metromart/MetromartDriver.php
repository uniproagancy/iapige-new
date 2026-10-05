<?php

namespace App\Services\Import\Drivers\Metromart;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

/**
 * Metromart.
 *
 * The spreadsheet lists models and, where we have negotiated one, a price; the
 * shop supplies everything else. A model the shop's search cannot find is
 * skipped — there would be nothing to describe.
 */
class MetromartDriver implements SupplierDriver
{
    protected MetromartClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new MetromartClient($supplier->config ?? []);
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

        $url = $this->client->findUrl($externalId);

        if (! $url) {
            return null;
        }

        $page = $this->client->page($url);

        return $page ? $this->toPayload($externalId, $row, $page) : null;
    }

    /* ------------------------------------------------------------------ mapping */

    protected function toPayload(string $model, SupplierStock $row, array $page): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        [$price, $old] = $this->prices($row, $page);

        if ($price <= 0) {
            return null;
        }

        $specs = array_map(fn ($spec) => [
            'name'   => $spec['name'],
            'value'  => $spec['value'],
            'locale' => $locale,
            'key'    => false,
            // nothing is filterable on arrival; that is decided in the admin
            'filterable' => false,
            'color'      => null,
        ], $page['specs'] ?? []);

        /*
         * Both sources must agree: the shop says whether it can hand the thing
         * over today, the spreadsheet how many we were promised.
         */
        $stock = $page['in_stock'] ? max(1, (int) $row->quantity) : 0;

        return new ProductPayload(
            externalId:   $model,
            sku:          $this->supplier->code.'-'.$model,
            costPrice:    $price,
            oldCostPrice: $old,
            stock:        $stock,
            brandName:    $page['brand'] ?? null,
            categoryName: ($row->data['category'] ?? null),
            translations: [$locale => [
                'name' => $page['name'],
            ]],
            specs:  $specs,
            images: $page['images'] ?? [],
        );
    }

    /**
     * The spreadsheet's price wins; the shop's own is retail.
     *
     * When we have no agreed figure the shop's price is used with an uplift,
     * because selling at a competitor's shelf price would leave us nothing.
     *
     * @return array{float, ?float}
     */
    protected function prices(SupplierStock $row, array $page): array
    {
        $agreed = (float) $row->cost_price;

        if ($agreed > 0) {
            return [$agreed, null];
        }

        $uplift = 1 + (float) ($this->supplier->config['site_price_uplift'] ?? 0);

        $retail = (float) ($page['price'] ?? 0);
        $sale = (float) ($page['sale'] ?? 0);

        // the shop publishes its promotion separately; the lower figure sells
        if ($sale > 0 && $sale < $retail) {
            return [round($sale * $uplift, 2), round($retail * $uplift, 2)];
        }

        $price = $sale > 0 ? $sale : $retail;

        return [round($price * $uplift, 2), null];
    }
}
