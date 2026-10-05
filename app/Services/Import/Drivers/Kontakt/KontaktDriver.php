<?php

namespace App\Services\Import\Drivers\Kontakt;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

/**
 * Kontakt.
 *
 * The price list carries the model, the quantity, the prices — and, behind the
 * model's own cell, a link to the product's page. So the spreadsheet decides
 * what exists and what it costs, and the link says where to read the rest.
 */
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

    protected function toPayload(string $model, SupplierStock $row, array $data, array $page): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        // the price list wins; the page's own price is the fallback
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
            // nothing is filterable on arrival; that is decided in the admin
            'filterable' => false,
            'color' => null,
        ], $page['specs'] ?? []);

        /*
         * Both sources must agree before a product counts as available: the
         * page says whether it is sold at all, the spreadsheet how many are
         * left. Either one alone has been wrong before.
         */
        $stock = $page['in_stock'] ? max(1, (int) $row->quantity) : 0;

        return new ProductPayload(
            externalId: $model,
            sku: $this->supplier->code.'-'.$model,
            costPrice: $price,
            oldCostPrice: $old,
            stock: $stock,
            brandName: $page['brand'] ?? null,
            categoryName: $data['category'] ?? null,
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
