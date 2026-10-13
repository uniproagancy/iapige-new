<?php

namespace App\Services\Import\Drivers\Thermocenter;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

/**
 * Thermocenter.
 *
 * The price list names a code and a link and nothing else — no price, no
 * quantity — so the linked page is the only source for both. That makes it the
 * one supplier where availability is decided entirely by what the page says.
 */
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
            return null;   // without a link there is nothing to describe
        }

        $page = $this->client->page($url);

        return $page && $page['name'] ? $this->toPayload($externalId, $row, $data, $page) : null;
    }

    /* ------------------------------------------------------------------ mapping */

    protected function toPayload(string $externalId, SupplierStock $row, array $data, array $page): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        // the price list has no price column; a cost one would still win
        $cost = (float) $row->cost_price ?: (float) $page['price'];

        if ($cost <= 0) {
            return null;
        }

        /*
         * Stock is the page's answer, limited by the price list.
         *
         * The file carries no quantity column, so the loader counts every row
         * it names as one available and drops a row it stops naming to zero.
         * Which means both halves still have their say: the page decides
         * whether the product is sold at all, and a code that fell out of the
         * latest file is gone whatever its page still shows.
         */
        $stock = $page['in_stock'] ? max(0, (int) $row->quantity) : 0;

        return new ProductPayload(
            externalId: $externalId,
            // their own code where the page publishes one, ours where it does not
            sku: $page['sku'] ? $this->supplier->code.'-'.$page['sku'] : $this->supplier->code.'-'.$externalId,
            costPrice: $cost,
            oldCostPrice: $page['old_price'],
            stock: $stock,
            brandName: $page['brand'] ?? null,
            // a category column would win; the page's breadcrumb is what there is
            categoryName: $data['category'] ?? $page['category'] ?? null,
            translations: [$locale => [
                'name' => $this->rewrite($page['name']),
                'description' => $this->rewrite($page['description']),
            ]],
            images: $page['images'] ?? [],
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

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
