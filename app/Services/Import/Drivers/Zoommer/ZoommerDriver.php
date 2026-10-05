<?php

namespace App\Services\Import\Drivers\Zoommer;

use App\Models\Supplier;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

class ZoommerDriver implements SupplierDriver
{
    protected ZoommerClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new ZoommerClient($supplier->config ?? []);
    }

    /** This source has no catalogue endpoint, so we walk its id range. */
    public function ids(): iterable
    {
        $from = (int) ($this->supplier->config['from'] ?? 1);
        $to = (int) ($this->supplier->config['to'] ?? 1000);

        for ($id = $from; $id <= $to; $id++) {
            yield $id;
        }
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        $byLocale = $this->client->fetch($externalId, $this->supplier->config['locales'] ?? ['ka', 'en']);

        return $byLocale ? $this->toPayload($externalId, $byLocale) : null;
    }

    /* ------------------------------------------------------------------ mapping */

    protected function toPayload(string $externalId, array $byLocale): ?ProductPayload
    {
        $base = $byLocale[array_key_first($byLocale)];
        $product = $base['product'];

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
                        continue;   // the source hides it, so do we
                    }

                    $specs[] = [
                        'name' => trim($spec['specificationName'] ?? ''),
                        'value' => trim($spec['specificationMeaning'] ?? ''),
                        'locale' => $locale,
                        'group' => trim($group['groupName'] ?? ''),
                        'key' => (bool) ($spec['isMainSpecification'] ?? false),
                        // the source links a spec to its own filter page exactly
                        // when that spec is filterable — no dedicated flag exists
                        'filterable' => filled($spec['specificationLinkedUrl'] ?? null),
                        'color' => ($spec['isColor'] ?? false) ? ($spec['colorValue'] ?? null) : null,
                    ];
                }
            }
        }

        // "-" means the source has no value; storing it is worse than storing nothing
        $specs = array_values(array_filter(
            $specs,
            fn ($s) => $s['name'] !== '' && $s['value'] !== '' && $s['value'] !== '-',
        ));

        return new ProductPayload(
            externalId: $externalId,
            // the barcode is shared between colour variants of one model, so the
            // source id is what keeps two variants from merging into one product
            sku: 'ZOOM-'.$externalId,
            costPrice: (float) ($product['previousPrice'] ?? $product['price'] ?? 0),
            oldCostPrice: isset($product['previousPrice']) ? (float) $product['price'] : null,
            stock: $this->stock($base),
            brandName: $product['brandName'] ?? null,
            categoryName: $product['categoryName'] ?? null,
            translations: $translations,
            specs: $specs,
            images: $this->images($product),
            isPreorder: (bool) ($product['preOrder'] ?? $product['onSaleSoon'] ?? false),
            releaseDate: $product['releaseDate'] ?? null,
            variantGroup: $this->variantGroup($externalId, $product),
        );
    }

    /**
     * The photo the source declares for this variant comes first; the rest of
     * the model's photos follow as a gallery.
     *
     * @return array<int, string>
     */
    protected function images(array $product): array
    {
        $main = trim((string) ($product['imageUrl'] ?? ''));
        $rest = array_values(array_filter($product['images'] ?? []));

        if ($main === '') {
            return $rest;
        }

        return array_values(array_unique(array_merge([$main], $rest)));
    }

    /**
     * Every version of one model lists all its siblings, so the smallest id in
     * that set is a key each of them computes identically — no extra table and
     * no second pass needed.
     */
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
            : null;   // a product with no siblings needs no group
    }

    /** Only the stores we can actually collect from count as stock. */
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

            if (! $cities || in_array($city, $cities, true)) {
                return (int) ($this->supplier->config['stock_units'] ?? 5);
            }
        }

        return 0;
    }
}
