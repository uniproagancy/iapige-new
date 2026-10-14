<?php

namespace App\Services\Import\Drivers\Midea;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Support\Facades\Log;

/**
 * Midea.
 *
 * The price list is the catalogue: it carries the category, the model, the
 * barcode, a description, three prices and the stock. The website is asked one
 * question per product — its own search, by model — and answers with the
 * title, the description, the photographs and the weight.
 *
 * So the spreadsheet decides what exists and what it costs, and the search
 * fills in what a customer reads. A product the site does not carry still
 * arrives; it simply has no picture.
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
         * the website calls it, B is what the warehouse calls it. Both are
         * tried, in that order.
         */
        $models = array_values(array_unique(array_filter([
            trim((string) ($data['site_model'] ?? '')),
            trim((string) ($data['model'] ?? '')),
        ])));

        return $this->toPayload($externalId, $row, $data, $this->siteCard($models));
    }

    /**
     * What the website knows about this model, or nothing.
     *
     * The site is an enrichment, never a requirement. Everything that decides
     * whether a product exists and what it costs comes from the price list, so
     * a site that is slow, down or has moved a page costs the product its
     * picture and nothing else.
     *
     * It cost far more than that once: an exception from the site travelled up
     * through fetch(), the job caught it, and the product was skipped
     * entirely — though the spreadsheet had its price and its stock the whole
     * time.
     *
     * @param  array<int, string>  $models
     * @return array<string, mixed>
     */
    protected function siteCard(array $models): array
    {
        if (! $models || ! ($this->supplier->config['read_site'] ?? true)) {
            return [];
        }

        try {
            foreach ($models as $model) {
                if ($card = $this->client->card($model)) {
                    return $card;
                }
            }
        } catch (\Throwable $e) {
            Log::channel('import')->warning('the site could not be read; importing without it', [
                'supplier' => $this->supplier->code,
                'models' => $models,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /* ------------------------------------------------------------------ mapping */

    protected function toPayload(string $barcode, SupplierStock $row, array $data, array $card): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        $model = trim((string) ($data['model'] ?? ''));
        $name = $this->name($data, $card, $model);

        if ($name === '') {
            return null;
        }

        [$price, $old] = $this->prices($data, $row);

        $specs = array_map(fn ($spec) => [
            'name' => $spec['name'],
            'value' => $spec['value'],
            'locale' => $locale,
            'key' => false,
            // filters are chosen in the admin, never on arrival
            'filterable' => false,
            'color' => null,
        ], $this->specsFromRow($data));

        return new ProductPayload(
            externalId: $barcode,
            // the barcode is the supplier's own identity and never changes
            sku: $this->supplier->code.'-'.$barcode,
            costPrice: $price,
            oldCostPrice: $old,
            stock: (int) $row->quantity,
            brandName: $this->supplier->config['brand'] ?? 'Midea',
            categoryName: $data['category'] ?? null,
            translations: [$locale => [
                'name' => $name,
                'summary' => $data['description'] ?? null,
                // the site's own copy reads better than the spreadsheet's line
                'description' => $card['description'] ?? $data['description'] ?? null,
            ]],
            specs: $specs,
            images: $card['images'] ?? [],
            // the search reports it; the spreadsheet never did
            weight: $card['weight'] ?? null,
        );
    }

    /**
     * The spreadsheet's name is a bare model code, which reads badly in a shop,
     * so the site's title wins when the two describe the same product.
     */
    protected function name(array $data, array $card, string $model): string
    {
        $fromSite = trim((string) ($card['name'] ?? ''));

        if ($fromSite !== '') {
            return $fromSite;
        }

        $category = trim((string) ($data['category'] ?? ''));

        return trim($category !== '' ? "{$category} {$model}" : $model);
    }

    /**
     * Three prices arrive: the retail list, the retail promotion and the dealer
     * promotion. What we pay is the dealer price; what the list is worth is the
     * retail one, so the markup rules decide the rest.
     *
     * @return array{float, ?float}
     */
    protected function prices(array $data, SupplierStock $row): array
    {
        $dealer = $this->money($data['cost'] ?? null) ?? (float) $row->cost_price;
        $retailSale = $this->money($data['retail_sale'] ?? null);
        $retail = $this->money($data['retail'] ?? null);

        if (($this->supplier->config['price_from'] ?? 'dealer') === 'retail') {
            // sell at the supplier's own promotional price, with the list price struck through
            return [$retailSale ?: $retail ?: $dealer, $retailSale && $retail > $retailSale ? $retail : null];
        }

        return [$dealer, null];
    }

    /** Warranty is the one spreadsheet field that reads as a specification. */
    protected function specsFromRow(array $data): array
    {
        $specs = [];

        if (! empty($data['warranty'])) {
            $specs[] = ['name' => __('product.warranty'), 'value' => $data['warranty']];
        }

        return $specs;
    }

    protected function money(?string $text): ?float
    {
        $digits = preg_replace('/[^\d.]/', '', str_replace(',', '.', (string) $text));

        return $digits === '' ? null : (float) $digits;
    }
}
