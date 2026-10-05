<?php

namespace App\Services\Import\Drivers\Elite;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

/**
 * Elite gives us stock as a spreadsheet of barcodes and details through a web
 * API keyed by their own product id. The two never meet in one request, so:
 *
 *   Excel  → supplier_stocks, keyed by BARCODE   (import:stock-file elite …)
 *   worker → product details, keyed by ID        (import:run elite)
 *
 * A product is therefore imported only when the barcode the API returns is
 * present in the latest spreadsheet.
 */
class EliteDriver implements SupplierDriver
{
    protected EliteClient $client;

    /** barcode => quantity, loaded once per run instead of per product */
    protected ?array $stock = null;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new EliteClient($supplier->config ?? []);
    }

    /** Their ids are sequential and the API is the only way to learn a barcode. */
    public function ids(): iterable
    {
        $from = (int) ($this->supplier->config['from'] ?? 1);
        $to = (int) ($this->supplier->config['to'] ?? 35000);

        for ($id = $from; $id <= $to; $id++) {
            yield $id;
        }
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        $data = $this->client->product($externalId);

        return $data ? $this->toPayload($externalId, $data) : null;
    }

    /* ------------------------------------------------------------------ mapping */

    protected function toPayload(string $externalId, array $data): ?ProductPayload
    {
        $p = $data['product'];
        $locale = $this->supplier->config['locale'] ?? 'ka';
        $barcode = trim((string) ($p['barCode'] ?? ''));

        // no barcode means we cannot match it against the spreadsheet at all
        if ($barcode === '') {
            return null;
        }

        $specs = [];

        foreach ($p['specificationGroup'] ?? [] as $group) {
            foreach ($group['specifications'] ?? [] as $spec) {
                if (! ($spec['isInProductPage'] ?? true)) {
                    continue;
                }

                $specs[] = [
                    'name' => trim($spec['specificationName'] ?? ''),
                    'value' => trim($spec['specificationMeaning'] ?? ''),
                    'locale' => $locale,
                    'group' => trim($group['groupName'] ?? ''),
                    'key' => (bool) ($spec['isMainSpecification'] ?? false),
                    'filterable' => filled($spec['specificationLinkedUrl'] ?? null),
                    'color' => ($spec['isColor'] ?? false) ? ($spec['colorValue'] ?? null) : null,
                ];
            }
        }

        $specs = array_values(array_filter(
            $specs,
            fn ($s) => $s['name'] !== '' && $s['value'] !== '' && $s['value'] !== '-',
        ));

        return new ProductPayload(
            externalId: $externalId,
            // the barcode is the identity here: it is what the stock file speaks
            sku: $this->supplier->code.'-'.$barcode,
            costPrice: (float) ($p['previousPrice'] ?? $p['price'] ?? 0),
            oldCostPrice: isset($p['previousPrice']) ? (float) $p['price'] : null,
            stock: $this->stockFor($barcode),
            brandName: $this->brand($p),
            categoryName: $p['categoryName'] ?? null,
            translations: [$locale => [
                'name' => $p['name'] ?? null,
                'description' => $p['description'] ?? null,
            ]],
            specs: $specs,
            images: array_values(array_filter($p['images'] ?? [])),
        );
    }

    /** What the latest spreadsheet says about this barcode. */
    protected function stockFor(string $barcode): int
    {
        $this->stock ??= SupplierStock::where('supplier_id', $this->supplier->id)
            ->pluck('quantity', 'external_id')
            ->all();

        return (int) ($this->stock[$barcode] ?? 0);
    }

    protected function brand(array $product): ?string
    {
        foreach ($product['specificationGroup'] ?? [] as $group) {
            foreach ($group['specifications'] ?? [] as $spec) {
                if (in_array($spec['specificationName'] ?? '', ['Brand', 'ბრენდი', 'Бренд'], true)) {
                    return $spec['specificationMeaning'] ?? null;
                }
            }
        }

        return $product['brandName'] ?? null;
    }
}
