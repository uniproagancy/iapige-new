<?php

namespace App\Services\Import\Drivers\Elite;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Concerns\ScansIdRange;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use App\Services\Import\TaxonomyResolver;
use RuntimeException;

class EliteDriver implements SupplierDriver
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

    public function fetch(string $externalId): ?ProductPayload
    {
        $data = $this->client->product($externalId);

        return $data ? $this->toPayload($externalId, $data) : null;
    }

    protected function toPayload(string $externalId, array $data): ?ProductPayload
    {
        $p = $data['product'];
        $locale = $this->supplier->config['locale'] ?? 'ka';
        $barcode = trim((string) ($p['barCode'] ?? ''));

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
            costPrice: (float) ($p['previousPrice'] ?? $p['price'] ?? 0),
            oldCostPrice: isset($p['previousPrice']) ? (float) $p['price'] : null,
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
