<?php

namespace App\Services\Import\Drivers\Comfo;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

/**
 * Comfo.
 *
 * The price list is three columns — a code, a quantity and a link — and that is
 * the whole of what the supplier sends. So the spreadsheet decides what exists
 * and how much of it is left, and the linked page says what it is and what it
 * costs.
 */
class ComfoDriver implements SupplierDriver
{
    protected ComfoClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new ComfoClient($supplier->config ?? []);
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

        /*
         * The page's price is the cost here, not the spreadsheet's.
         *
         * Comfo's file has no price column at all — a code, a quantity and a
         * link is everything it carries — so the page is the only source. A
         * cost column would still win if the supplier ever adds one.
         */
        $cost = (float) $row->cost_price ?: (float) $page['price'];

        if ($cost <= 0) {
            return null;
        }

        $specs = array_map(fn ($spec) => [
            'name' => $spec['name'],
            'value' => $spec['value'],
            'locale' => $locale,
            'key' => false,
            // nothing is filterable on arrival; that is decided in the admin
            'filterable' => false,
            'color' => null,
        ], $page['specs'] ?? []);

        /*
         * Both sources must agree before a product counts as available: the
         * page says whether it is sold at all, the spreadsheet how many are
         * left. Either one alone has been wrong before.
         */
        $stock = $page['in_stock'] ? max(0, (int) $row->quantity) : 0;

        return new ProductPayload(
            externalId: $externalId,
            sku: $this->supplier->code.'-'.$externalId,
            costPrice: $cost,
            oldCostPrice: null,
            stock: $stock,
            brandName: $page['brand'] ?? null,
            // a category column would win; the page's own is what there is
            categoryName: $data['category'] ?? $page['category'] ?? null,
            translations: [$locale => [
                'name' => $this->rewrite($page['name']),
                'description' => $this->rewrite($page['description']),
            ]],
            specs: $specs,
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
