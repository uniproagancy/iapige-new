<?php

namespace App\Services\Import\Drivers\Elite;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ChecksImportSource;
use App\Services\Import\Concerns\ScansIdRange;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use App\Services\Import\SyncsStockFile;
use App\Services\Import\TaxonomyResolver;
use App\Support\Figure;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EliteDriver implements ChecksImportSource, SupplierDriver, SyncsStockFile
{
    use ScansIdRange;

    protected EliteClient $client;

    protected ?array $stock = null;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new EliteClient($supplier->config ?? []);
    }

    public function ids(): iterable
    {
        if (! $this->stockFile()) {
            throw new RuntimeException(
                'Elite has no stock file loaded, so nothing it returns can be matched. '
                .'Run: php artisan import:stock-file elite <file.xlsx>'
            );
        }

        yield from $this->idsToScan(defaultTo: 35000);
    }

    /* Before a run, so what the stock file says holds even for a product the
       run never reaches: one gone from the file is skipped by toPayload, and
       a skipped product used to keep the stock it had. */
    public function checkSource(): void
    {
        if ($this->stockFile()) {
            $this->syncStockFile();
        }
    }

    /* Elite's offers are kept under its site ids; the file speaks of barcodes,
       which live in the product's sku as "elite-<barcode>". */
    public function syncStockFile(): int
    {
        /* Read afresh: a file loaded since this driver was made replaced the
           one it remembers. */
        $this->stock = null;
        $file = $this->stockFile();

        if (! $file) {
            return 0;
        }

        $prefix = strtolower($this->supplier->code).'-';
        $changed = [];

        ProductOffer::where('supplier_id', $this->supplier->id)
            ->join('products', 'products.id', '=', 'product_offers.product_id')
            ->select('product_offers.id', 'product_offers.product_id', 'product_offers.stock', 'products.sku')
            ->chunkById(500, function ($offers) use ($file, $prefix, &$changed) {
                foreach ($offers as $offer) {
                    $sku = (string) $offer->sku;

                    if (! str_starts_with(strtolower($sku), $prefix)) {
                        continue;
                    }

                    $quantity = max(0, (int) ($file[substr($sku, strlen($prefix))] ?? 0));

                    if ($quantity !== (int) $offer->stock) {
                        ProductOffer::whereKey($offer->id)->update(['stock' => $quantity, 'synced_at' => now()]);
                        $changed[$offer->product_id] = true;
                    }
                }
            }, 'product_offers.id', 'id');

        $importer = app(ProductImporter::class);

        foreach (Product::whereIn('id', array_keys($changed))->get() as $product) {
            $importer->refreshFromOffers($product);
        }

        if ($changed) {
            Log::channel('import')->info('elite stock set from the stock file', ['products' => count($changed)]);
        }

        return count($changed);
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        $data = $this->client->product($externalId);

        return $data ? $this->toPayload($externalId, $data) : null;
    }

    protected function prices(array $product): array
    {
        $current = Figure::amount($product['price'] ?? null, Figure::DOT);
        $previous = Figure::amount($product['previousPrice'] ?? null, Figure::DOT);

        if ($previous > 0 && $current > 0) {
            return [min($current, $previous), max($current, $previous)];
        }

        return [$current ?: $previous, null];
    }

    protected function toPayload(string $externalId, array $data): ?ProductPayload
    {
        $p = $data['product'];
        $locale = $this->supplier->config['locale'] ?? 'ka';
        $barcode = trim((string) ($p['barCode'] ?? ''));

        [$costPrice, $oldCostPrice] = $this->prices($p);

        if ($barcode === '') {
            return null;
        }

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

            sku: $this->supplier->code.'-'.$barcode,
            costPrice: $costPrice,
            oldCostPrice: $oldCostPrice,
            stock: $this->stockFor($barcode),
            brandName: $this->brand($p),
            categoryName: TaxonomyResolver::trail(
                $p['categoryName'] ?? null,
                $p['subCategoryName'] ?? null,
            ),
            translations: [$locale => [
                'name' => $p['name'] ?? null,
                'description' => $p['description'] ?? null,
            ]],
            specs: $specs,
            images: $this->images($p),
        );
    }

    protected function images(array $product): array
    {
        $main = trim((string) ($product['imageUrl'] ?? ''));
        $rest = array_values(array_filter($product['images'] ?? []));

        $urls = $main === '' ? $rest : array_values(array_unique(array_merge([$main], $rest)));

        return array_map(fn ($url) => $this->client->imageUrl($url), $urls);
    }

    protected function stockFor(string $barcode): int
    {
        return (int) ($this->stockFile()[$barcode] ?? 0);
    }

    protected function listedInStockFile(string $barcode): bool
    {
        return array_key_exists($barcode, $this->stockFile());
    }

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
