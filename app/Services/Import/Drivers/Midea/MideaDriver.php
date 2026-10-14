<?php

namespace App\Services\Import\Drivers\Midea;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

/**
 * Midea.
 *
 * Written the way the working version of this importer was: ask the site's
 * search for the model, and take the product from what comes back. The price
 * list says which models to ask about and how many of each are left; the site
 * says everything else — the title, the description, the prices and the
 * photographs.
 *
 * A model the site does not sell is not imported. That is the rule the working
 * version had, and it is why its catalogue has a picture on every card: there
 * is no half-imported product, built out of a spreadsheet row, waiting for a
 * photograph that is never coming.
 */
class MideaDriver implements SupplierDriver
{
    protected MideaClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new MideaClient($supplier->config ?? []);
    }

    /** Every barcode in the last uploaded price list. */
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

        /*
         * Two columns carry a model and neither is reliably filled: K is what
         * the website calls it, B is what the warehouse calls it.
         */
        foreach ([$data['site_model'] ?? '', $data['model'] ?? ''] as $model) {
            $model = trim((string) $model);

            if ($model === '') {
                continue;
            }

            if ($found = $this->client->searchByName($model)) {
                return $this->toPayload($externalId, $row, $data, $found);
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ mapping */

    /**
     * @param  array<string, mixed>  $found  what the search returned
     */
    protected function toPayload(string $barcode, SupplierStock $row, array $data, array $found): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        if ($found['name'] === '') {
            return null;
        }

        [$price, $old] = $this->prices($found, $data, $row);

        if ($price <= 0) {
            return null;
        }

        $specs = [];

        // the warranty is the one spreadsheet field that reads as a specification
        if (! empty($data['warranty'])) {
            $specs[] = [
                'name' => __('product.warranty'),
                'value' => $data['warranty'],
                'locale' => $locale,
                'key' => false,
                'filterable' => false,
                'color' => null,
            ];
        }

        /*
         * Both sources must agree before a product counts as available: the
         * site says whether it is sold at all, the spreadsheet how many are
         * left.
         */
        $stock = $found['in_stock'] ? max(0, (int) $row->quantity) : 0;

        return new ProductPayload(
            externalId: $barcode,
            // the barcode is the supplier's own identity and never changes
            sku: $this->supplier->code.'-'.$barcode,
            costPrice: $price,
            oldCostPrice: $old,
            stock: $stock,
            brandName: $found['brand'] ?? 'Midea',
            categoryName: $data['category'] ?? null,
            translations: [$locale => [
                'name' => $found['name'],
                'summary' => $data['description'] ?? null,
                'description' => $found['description'] ?? $data['description'] ?? null,
            ]],
            specs: $specs,
            images: $found['images'] ?? [],
            weight: $found['weight'] ?? null,
        );
    }

    /**
     * What we sell at, and the figure to strike through.
     *
     * The site's own price first, because that is the one a customer sees
     * on midea.ge and the one the working version used: price is the shelf
     * price, sale_price the promotion when there is one.
     *
     * The spreadsheet is the fallback for a product the site prices at
     * nothing, and price_from still decides which of its columns to read.
     *
     * @return array{0: float, 1: ?float}
     */
    protected function prices(array $found, array $data, SupplierStock $row): array
    {
        if (($found['price'] ?? 0) > 0) {
            return $found['sale_price']
                ? [(float) $found['sale_price'], (float) $found['price']]
                : [(float) $found['price'], null];
        }

        $dealer = $this->money($data['cost'] ?? null) ?? (float) $row->cost_price;
        $retailSale = $this->money($data['retail_sale'] ?? null);
        $retail = $this->money($data['retail'] ?? null);

        if (($this->supplier->config['price_from'] ?? 'dealer') === 'retail') {
            return [$retailSale ?: $retail ?: $dealer, $retailSale && $retail > $retailSale ? $retail : null];
        }

        return [$dealer, null];
    }

    protected function money(?string $text): ?float
    {
        $digits = preg_replace('/[^\d.]/', '', str_replace(',', '.', (string) $text));

        return $digits === '' ? null : (float) $digits;
    }
}
