<?php

namespace App\Services\Import\Drivers\Elite;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use RuntimeException;

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

    /**
     * Their ids are sequential and the API is the only way to learn a barcode.
     *
     * Refused outright with no spreadsheet loaded: the scan would be tens of
     * thousands of requests to Elite that could not import a single product,
     * because the file is what says which barcodes are theirs to sell.
     *
     * @throws RuntimeException
     */
    public function ids(): iterable
    {
        if (! $this->stockFile()) {
            throw new RuntimeException(
                'Elite has no stock file loaded, so nothing it returns can be matched. '
                .'Run: php artisan import:stock-file elite <file.xlsx>'
            );
        }

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

        /*
         * The spreadsheet decides what we sell, as this class has always
         * claimed — and never enforced. Only an empty barcode was refused, so
         * every product the id scan happened to find was published whether
         * Elite stocked it or not: thousands of rows at stock zero, none of
         * them in the file anyone had sent us.
         */
        if (! $this->listedInStockFile($barcode)) {
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
            images: $this->images($p),
        );
    }

    /**
     * Every photograph of this product, the declared one first.
     *
     * Only the "images" array was read, and the source does not always put its
     * own "imageUrl" in it — product 1000 names a main picture that appears
     * nowhere in the list, so that product came in with two photographs out of
     * three and the one the supplier chose to lead with was the one lost.
     *
     * @return array<int, string>
     */
    protected function images(array $product): array
    {
        $main = trim((string) ($product['imageUrl'] ?? ''));
        $rest = array_values(array_filter($product['images'] ?? []));

        $urls = $main === '' ? $rest : array_values(array_unique(array_merge([$main], $rest)));

        // through the proxy when there is one: static.ee.ge refuses the server
        return array_map(fn ($url) => $this->client->imageUrl($url), $urls);
    }

    /** What the latest spreadsheet says about this barcode. */
    protected function stockFor(string $barcode): int
    {
        return (int) ($this->stockFile()[$barcode] ?? 0);
    }

    /**
     * Whether Elite sent us this barcode at all.
     *
     * Distinct from stockFor(), because a barcode listed with a quantity of
     * zero is a product Elite sells and has run out of — worth keeping, shown
     * as unavailable — while a barcode that is not in the file is not theirs to
     * sell and has no business in the catalogue.
     */
    protected function listedInStockFile(string $barcode): bool
    {
        return array_key_exists($barcode, $this->stockFile());
    }

    /**
     * barcode => quantity, read once per run.
     *
     * @return array<string, int>
     */
    protected function stockFile(): array
    {
        return $this->stock ??= SupplierStock::where('supplier_id', $this->supplier->id)
            ->pluck('quantity', 'external_id')
            ->all();
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
