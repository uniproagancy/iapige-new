<?php

namespace App\Services\Import\Drivers\Citrus;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

/**
 * Citrus.
 *
 * There is no price list here. Somebody pastes the addresses of the products
 * they want and each one is looked up through the site's own API, which
 * answers with everything: the name, both prices, the stock, the brand, the
 * gallery and the specification list.
 *
 * So the pasted addresses are the catalogue — they live in supplier_stocks
 * like any other supplier's rows, and the import runs over them the same way.
 */
class CitrusDriver implements SupplierDriver
{
    protected CitrusClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new CitrusClient($supplier->config ?? []);
    }

    /** Every address somebody has added. */
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

        $slug = $row->data['slug'] ?? null;

        if (! $slug) {
            return null;
        }

        $found = $this->client->bySlug($slug);

        return $found ? $this->toPayload($externalId, $row, $found) : null;
    }

    /* ------------------------------------------------------------------ mapping */

    /** @param  array<string, mixed>  $found */
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
            // the site marks what it filters its own catalogue by
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

        /*
         * Both halves have their say: stock_status is whether it is sold at
         * all, stock how many are left. The row's own quantity is not used —
         * nobody typed one, the address is the whole input.
         */
        $stock = $found['in_stock'] ? max(0, (int) $found['stock']) : 0;

        return new ProductPayload(
            externalId: $externalId,
            // their own article number, which is on the product page and on
            // the shelf label, so an operator can look it up either way
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

    /**
     * Their name out of our copy.
     *
     * A supplier writes its own shop into every description, which would send
     * our customers to them. The pairs are configured rather than written in,
     * because each supplier does this with a different word.
     */
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
