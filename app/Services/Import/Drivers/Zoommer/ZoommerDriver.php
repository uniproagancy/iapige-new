<?php

namespace App\Services\Import\Drivers\Zoommer;

use App\Models\Supplier;
use App\Services\Import\Concerns\ScansIdRange;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use App\Services\Import\TaxonomyResolver;
use App\Support\Figure;

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
        $specs = $this->storageCapacitySpecs($specs, $translations);

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

    /**
     * Zoommer sometimes puts a storage technology (UFS/eMMC) in its capacity
     * field. A single explicit RAM/storage pair in every translated SKU name,
     * corroborated by every separate RAM field, can restore that capacity.
     * Any missing, conflicting or already meaningful storage row is left alone.
     */
    protected function storageCapacitySpecs(array $specs, array $translations): array
    {
        $storageNames = ['შიდამეხსიერება', 'ჩაშენებულიმეხსიერება', 'internalstorage', 'internalmemory', 'builtinmemory'];
        $ramNames = ['ოპერატიულიმეხსიერება', 'ram', 'rammemory', 'operativememory'];
        $nameKey = fn ($name) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $name)));
        $pair = null;
        $storageRows = [];
        $technology = null;

        if ($translations === []) {
            return $specs;
        }

        foreach ($translations as $locale => $translation) {
            $title = trim((string) ($translation['name'] ?? ''));
            $matches = [];
            preg_match_all('/(?<![\p{L}\p{N}.,])(?<ram>\d+)\s*(?:GB\s*)?\/\s*(?<storage>\d+)\s*GB(?![\p{L}\p{N}])/iu', $title, $matches, PREG_SET_ORDER);
            if (count($matches) !== 1 || (int) $matches[0]['ram'] <= 0 || (int) $matches[0]['storage'] <= 0) {
                return $specs;
            }
            $said = [(int) $matches[0]['ram'], (int) $matches[0]['storage']];
            if ($pair !== null && $pair !== $said) {
                return $specs;
            }
            $pair = $said;
            $hasRam = false;
            $hasStorage = false;
            foreach ($specs as $index => $spec) {
                if (($spec['locale'] ?? null) !== $locale) {
                    continue;
                }
                $name = $nameKey($spec['name'] ?? '');
                $value = trim((string) ($spec['value'] ?? ''));
                if (in_array($name, $ramNames, true)) {
                    if (! preg_match('/\A(?<ram>\d+)\s*(?:GB|გბ)\z/iu', $value, $ram) || (int) $ram['ram'] !== $pair[0]) {
                        return $specs;
                    }
                    $hasRam = true;
                }
                if (! in_array($name, $storageNames, true)) {
                    continue;
                }
                if (! preg_match('/\A(?:UFS|eMMC)\s+\d+(?:\.\d+)?\z/iu', $value)) {
                    return $specs;
                }
                $saidTechnology = mb_strtolower(preg_replace('/\s+/u', ' ', $value));
                if ($technology !== null && $technology !== $saidTechnology) {
                    return $specs;
                }
                $technology = $saidTechnology;
                $hasStorage = true;
                $storageRows[] = $index;
            }
            if (! $hasRam || ! $hasStorage) {
                return $specs;
            }
        }

        foreach ($storageRows as $index) {
            $specs[$index]['value'] = $pair[1].' GB';
        }

        return $specs;
    }

    protected function sku(string $externalId, array $product): string
    {
        $barcode = trim((string) ($product['barCode'] ?? ''));

        return $barcode !== '' ? $barcode : 'ZOOM-'.$externalId;
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
