<?php

namespace App\Services\Import\Drivers\Zoommer;

use App\Models\Supplier;
use App\Services\Import\Concerns\ScansIdRange;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use App\Services\Import\TaxonomyResolver;

class ZoommerDriver implements SupplierDriver
{
    use ScansIdRange;

    protected ZoommerClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new ZoommerClient($supplier->config ?? []);
    }

    public function ids(): iterable
    {

        $this->client->fetch((string) ($this->supplier->config['from'] ?? 1), ['ka']);

        yield from $this->idsToScan(defaultTo: 1000);
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        $byLocale = $this->client->fetch($externalId, $this->supplier->config['locales'] ?? ['ka', 'en']);

        return $byLocale ? $this->toPayload($externalId, $byLocale) : null;
    }

    protected function toPayload(string $externalId, array $byLocale): ?ProductPayload
    {
        $base = $byLocale[array_key_first($byLocale)];
        $product = $base['product'];

        [$costPrice, $oldCostPrice] = $this->prices($product);

        $translations = [];
        $specs = [];

        foreach ($byLocale as $locale => $data) {
            $p = $data['product'];

            $translations[$locale] = [
                'name' => $p['name'] ?? null,
                'description' => $p['description'] ?? null,
            ];

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
        }

        $specs = array_values(array_filter(
            $specs,
            fn ($s) => $s['name'] !== '' && $s['value'] !== '' && $s['value'] !== '-',
        ));

        return new ProductPayload(
            externalId: $externalId,
            sku: $this->sku($externalId, $product),
            costPrice: $costPrice,
            oldCostPrice: $oldCostPrice,
            stock: $this->stock($base),
            brandName: $product['brandName'] ?? null,

            categoryName: TaxonomyResolver::trail(
                $product['categoryName'] ?? null,
                $product['subCategoryName'] ?? null,
            ),
            translations: $translations,
            specs: $specs,
            images: $this->images($product),
            isPreorder: (bool) ($product['preOrder'] ?? $product['onSaleSoon'] ?? false),
            releaseDate: $product['releaseDate'] ?? null,
            variantGroup: $this->variantGroup($externalId, $product),
        );
    }

    protected function sku(string $externalId, array $product): string
    {
        $barcode = trim((string) ($product['barCode'] ?? ''));

        return $barcode !== '' ? $barcode : 'ZOOM-'.$externalId;
    }

    protected function prices(array $product): array
    {
        $current = (float) ($product['price'] ?? 0);
        $previous = (float) ($product['previousPrice'] ?? 0);

        if ($previous > 0 && $current > 0) {
            return [min($current, $previous), max($current, $previous)];
        }

        return [$current ?: $previous, null];
    }

    protected function images(array $product): array
    {
        $main = trim((string) ($product['imageUrl'] ?? ''));
        $rest = array_values(array_filter($product['images'] ?? []));

        if ($main === '') {
            return $rest;
        }

        return array_values(array_unique(array_merge([$main], $rest)));
    }

    protected function variantGroup(string $externalId, array $product): ?string
    {
        $ids = [(int) $externalId];

        foreach ($product['keySpecification'] ?? [] as $spec) {
            foreach ($spec['specificationMeaningsList'] ?? [] as $option) {
                if (! empty($option['productId'])) {
                    $ids[] = (int) $option['productId'];
                }
            }
        }

        $ids = array_unique($ids);

        return count($ids) > 1
            ? $this->supplier->code.'-'.min($ids)
            : null;
    }

    protected function stock(array $data): int
    {
        $product = $data['product'] ?? [];

        if (! ($product['isInStock'] ?? false)) {
            return 0;
        }

        $cities = array_map(
            fn ($c) => mb_strtolower(trim($c)),
            $this->supplier->config['stock_cities'] ?? ['თბილისი', 'Tbilisi'],
        );

        foreach ($data['availabilityInStores'] ?? [] as $store) {
            if (empty($store['inStock'])) {
                continue;
            }

            $city = mb_strtolower(trim((string) ($store['city'] ?? '')));

            $matches = ! $cities || array_filter($cities, fn ($c) => str_contains($city, $c));

            if ($matches) {
                return (int) ($this->supplier->config['stock_units'] ?? 5);
            }
        }

        return 0;
    }
}
