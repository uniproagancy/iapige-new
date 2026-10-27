<?php

namespace App\Services\Import\Drivers\Alta;

use App\Models\Supplier;
use App\Services\Import\ProductPayload;

/*
 | Turns one answer from alta.ge's details endpoint into a product.
 |
 | The answer is pasted in by hand rather than fetched, so this reads whatever
 | arrives and says what it could not make sense of instead of throwing: the
 | person pasting is standing right there and can fix it.
 */
class AltaJson
{
    public function __construct(protected Supplier $supplier) {}

    /**
     * What the shop would make of this answer, without writing anything.
     *
     * @return array{payload: ?ProductPayload, errors: array<int, string>, seen: array<string, mixed>}
     */
    public function read(string $json): array
    {
        $data = json_decode(trim($json), true);

        if (! is_array($data)) {
            return $this->no(__('admin.json_not_json'));
        }

        /* Either the whole answer or just the product block inside it. */
        $product = $data['product'] ?? $data;

        if (! is_array($product) || ! isset($product['id'])) {
            return $this->no(__('admin.json_no_product'));
        }

        $errors = [];

        $code = trim((string) ($product['barCode'] ?? ''));

        if ($code === '') {
            /* Without the bar code there is nothing to tie this to the price
               list, so the shop's own id stands in and is said out loud. */
            $code = 'p'.$product['id'];
            $errors[] = __('admin.json_no_barcode', ['id' => $product['id']]);
        }

        $name = trim((string) ($product['name'] ?? ''));
        $price = is_numeric($product['price'] ?? null) ? (float) $product['price'] : 0.0;

        if ($name === '') {
            $errors[] = __('admin.json_no_name');
        }

        if ($price <= 0) {
            $errors[] = __('admin.json_no_price');
        }

        $seen = $this->summarise($data, $product, $code, $price);

        if ($name === '' || $price <= 0) {
            return ['payload' => null, 'errors' => $errors, 'seen' => $seen];
        }

        $locale = $this->supplier->config['locale'] ?? 'ka';
        $previous = is_numeric($product['previousPrice'] ?? null) ? (float) $product['previousPrice'] : 0.0;

        $payload = new ProductPayload(
            externalId: $code,
            sku: $this->supplier->code.'-'.$code,
            costPrice: $price,

            /* Only a higher figure is a previous price; the field repeats the
               current one when nothing is off. */
            oldCostPrice: $previous > $price ? $previous : null,
            stock: $seen['stock'],
            brandName: trim((string) ($product['brandName'] ?? '')) ?: null,
            categoryName: trim((string) ($product['categoryName'] ?? '')) ?: null,
            translations: [$locale => array_filter([
                'name' => $name,
                'description' => $this->text($product['description'] ?? null),
            ])],
            specs: $this->specs($product, $locale),
            images: $seen['images'],
            weight: $seen['weight'],
        );

        return ['payload' => $payload, 'errors' => $errors, 'seen' => $seen];
    }

    /**
     * @return array<string, mixed>
     */
    protected function summarise(array $data, array $product, string $code, float $price): array
    {
        $branches = (array) ($data['availabilityInStores'] ?? []);

        $withStock = count(array_filter(
            $branches,
            fn ($branch) => (bool) ($branch['inStock'] ?? false),
        ));

        return [
            'id' => (int) $product['id'],
            'code' => $code,
            'name' => trim((string) ($product['name'] ?? '')),
            'price' => $price,
            'previous' => (float) ($product['previousPrice'] ?? 0),
            'brand' => trim((string) ($product['brandName'] ?? '')) ?: null,
            'category' => trim((string) ($product['categoryName'] ?? '')) ?: null,
            'parent_category' => trim((string) ($product['parentCategoryName'] ?? '')) ?: null,
            'images' => $this->images($product),
            'specs_count' => count($this->specs($product, 'ka')),
            'weight' => $this->weight($product),
            'warranty' => (int) ($product['warranty'] ?? 0) ?: null,
            'branches' => count($branches),
            'branches_in_stock' => $withStock,
            'stock' => $this->stock($product, $withStock),
            'url' => ($route = trim((string) ($product['route'] ?? ''))) !== ''
                ? 'https://alta.ge/'.ltrim($route, '/')
                : null,
        ];
    }

    /*
     | What the shop front says it holds, and failing that, how many branches
     | have one on the shelf. Neither is our own count -- whoever pastes the
     | answer in can change it before saving.
     */
    protected function stock(array $product, int $withStock): int
    {
        $held = (int) ($product['storageQuantity'] ?? 0);

        return $held > 0 ? $held : $withStock;
    }

    /**
     * @return array<int, string>
     */
    protected function images(array $product): array
    {
        $urls = [];

        foreach (array_merge(
            [$product['imageUrl'] ?? null],
            (array) ($product['images'] ?? []),
        ) as $url) {
            $url = trim((string) $url);

            if (str_starts_with($url, 'http') && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function specs(array $product, string $locale): array
    {
        $specs = [];
        $seen = [];

        foreach ((array) ($product['specificationGroup'] ?? []) as $group) {
            foreach ((array) ($group['specifications'] ?? []) as $spec) {
                $name = trim((string) ($spec['specificationName'] ?? ''));
                $value = trim((string) ($spec['specificationMeaning'] ?? ''));

                if ($name === '' || $value === '') {
                    continue;
                }

                /* The brand is a product's own field here, not a line in its
                   table of specifications. */
                if ($spec['isBrand'] ?? false) {
                    continue;
                }

                $key = mb_strtolower($name);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $specs[] = [
                    'name' => $name,
                    'value' => $value,
                    'locale' => $locale,
                    'key' => (bool) ($spec['isMainSpecification'] ?? false),
                    'filterable' => true,
                    'color' => ($spec['isColor'] ?? false) ? ($spec['colorValue'] ?? null) : null,
                ];
            }
        }

        if ($warranty = (int) ($product['warranty'] ?? 0)) {
            $specs[] = [
                'name' => __('product.warranty'),
                'value' => __('admin.json_warranty_months', ['count' => (int) round($warranty / 30)]),
                'locale' => $locale,
                'key' => false,
                'filterable' => false,
                'color' => null,
            ];
        }

        return $specs;
    }

    /*
     | The weight is a line in the specifications rather than a field, written
     | as "217 g" or "1.4 kg". Grams are what a payload carries.
     */
    protected function weight(array $product): ?int
    {
        foreach ((array) ($product['specificationGroup'] ?? []) as $group) {
            foreach ((array) ($group['specifications'] ?? []) as $spec) {
                if (mb_strtolower(trim((string) ($spec['specificationName'] ?? ''))) !== 'weight') {
                    continue;
                }

                $value = mb_strtolower(trim((string) ($spec['specificationMeaning'] ?? '')));

                if (! preg_match('/([\d.,]+)\s*(kg|g)\b/u', $value, $m)) {
                    continue;
                }

                $number = (float) str_replace(',', '.', $m[1]);

                return (int) round($m[2] === 'kg' ? $number * 1000 : $number);
            }
        }

        return null;
    }

    protected function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array{payload: null, errors: array<int, string>, seen: array<string, mixed>}
     */
    protected function no(string $why): array
    {
        return ['payload' => null, 'errors' => [$why], 'seen' => []];
    }
}
