<?php

namespace App\Services\Import\Drivers\Elitlight;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Support\Facades\Log;

class ElitlightDriver implements SupplierDriver
{
    protected ElitlightClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new ElitlightClient($supplier->config ?? []);
    }

    public function ids(): iterable
    {
        return SupplierStock::where('supplier_id', $this->supplier->id)
            /* No ordering here: lazyById pages on the primary key, and a
               second sort makes it page over the wrong rows and skip some. */
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

        $cost = (float) $row->cost_price;

        if ($cost <= 0) {
            return null;
        }

        $variant = $this->client->variant($externalId);

        /* The price list reaches further than the shop window: garlands and
           other lines carry a price but no card to describe them. */
        if (! $variant) {
            Log::channel('import')->info('elitlight code is not in the catalogue', [
                'code' => $externalId,
            ]);

            return null;
        }

        return $this->toPayload($externalId, $row, $variant, $cost);
    }

    protected function toPayload(string $code, SupplierStock $row, array $variant, float $cost): ProductPayload
    {
        $config = $this->supplier->config ?? [];

        $height = $variant['height'] ?? null;

        $translations = [];

        foreach (['ka' => 'სმ', 'en' => 'cm'] as $locale => $unit) {
            $name = trim((string) ($variant['names'][$locale] ?? ''));

            if ($name === '') {
                continue;
            }

            $translations[$locale] = [
                'name' => $height ? $name.' '.$height.' '.$unit : $name,
                'summary' => trim((string) ($variant['descriptions'][$locale] ?? '')) ?: null,
            ];
        }

        if (! $translations) {
            return new ProductPayload(
                externalId: $code,
                sku: $this->supplier->code.'-'.$code,
                costPrice: $cost,
                oldCostPrice: null,
                stock: $this->stockFor($row),
                brandName: $config['brand'] ?? null,
                categoryName: $config['category'] ?? null,
                translations: ['ka' => ['name' => $code]],
            );
        }

        return new ProductPayload(
            externalId: $code,
            sku: $this->supplier->code.'-'.$code,
            costPrice: $cost,
            oldCostPrice: null,
            stock: $this->stockFor($row),
            brandName: $config['brand'] ?? null,
            categoryName: $config['category'] ?? null,
            translations: $translations,
            specs: $this->specs($variant),
            images: array_filter([$this->client->imageUrl($variant['image'] ?? null)]),
            variantGroup: $this->variantGroup($variant),
        );
    }

    protected function specs(array $variant): array
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        $specs = [];

        if ($height = $variant['height'] ?? null) {
            $specs[] = [
                'name' => 'სიმაღლე',
                'value' => $height.' სმ',
                'locale' => $locale,
                'key' => true,
                'filterable' => true,
                'color' => null,
            ];
        }

        foreach ($variant['tags'] ?? [] as $tag) {
            $label = self::TAGS[$tag] ?? null;

            if (! $label) {
                continue;
            }

            $specs[] = [
                'name' => $label[0],
                'value' => $label[1],
                'locale' => $locale,
                'key' => false,
                'filterable' => true,
                'color' => null,
            ];
        }

        return $specs;
    }

    protected const TAGS = [
        'snow' => ['თოვლის ეფექტი', 'კი'],
        'led' => ['განათება', 'LED'],
        'mini' => ['ტიპი', 'მინი'],
        'giant' => ['ტიპი', 'გარე'],
    ];

    protected function variantGroup(array $variant): ?string
    {
        $model = $variant['model_id'] ?? null;

        return $model ? $this->supplier->code.'-'.$model : null;
    }

    protected function stockFor(SupplierStock $row): int
    {
        $quantity = (int) $row->quantity;

        if ($quantity > 0) {
            return $quantity;
        }

        return (int) ($this->supplier->config['default_stock'] ?? 0);
    }
}
