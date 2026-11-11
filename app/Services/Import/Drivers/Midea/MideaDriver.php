<?php

namespace App\Services\Import\Drivers\Midea;

use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\RoomArea;
use App\Services\Import\SourceUnavailable;
use App\Services\Import\SupplierDriver;
use App\Support\Figure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class MideaDriver implements SupplierDriver
{
    protected MideaClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new MideaClient($supplier->config ?? []);
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

        $data = $row->data ?? [];
        $unanswered = false;

        foreach ([$data['site_model'] ?? '', $data['model'] ?? ''] as $model) {
            $model = trim((string) $model);

            if ($model === '') {
                continue;
            }

            try {
                $found = $this->client->searchByName($model);
            } catch (ConnectionException $e) {
                $unanswered = true;

                continue;
            }

            if ($found) {
                return $this->toPayload($externalId, $row, $data, $found);
            }
        }

        /* midea.ge answers slowly and sometimes not at all. A product already
           in the shop takes its price and stock from the sheet meanwhile; one
           that is not -- new, or gone since -- needs the site for its name and
           pictures, so the job is tried again later instead of the row being
           dropped, which is how eighty products in stock went missing. */
        if ($payload = $this->fromSheetAlone($externalId, $row, $data)) {
            return $payload;
        }

        if ($unanswered) {
            throw new SourceUnavailable("midea.ge did not answer for {$externalId}");
        }

        return null;
    }

    protected function fromSheetAlone(string $externalId, SupplierStock $row, array $data): ?ProductPayload
    {
        $offer = ProductOffer::where('supplier_id', $this->supplier->id)
            ->where('external_id', $externalId)
            ->with('product')
            ->first();

        if (! $offer?->product) {
            return null;
        }

        [$price, $old] = $this->prices([], $data, $row);

        if ($price <= 0) {
            return null;
        }

        Log::channel('import')->info('midea site unavailable, price taken from the sheet', [
            'external' => $externalId,
            'product' => $offer->product_id,
        ]);

        return new ProductPayload(
            externalId: $externalId,
            sku: $offer->product->sku,
            costPrice: $price,
            oldCostPrice: $old,
            stock: max(0, (int) $row->quantity),
            brandName: null,
            categoryName: null,
            translations: [],
        );
    }

    protected function toPayload(string $barcode, SupplierStock $row, array $data, array $found): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        if ($found['name'] === '') {
            return null;
        }

        [$price, $old] = $this->prices($found, $data, $row);

        if ($price <= 0) {
            return null;
        }

        $specs = [];

        if (! empty($data['warranty'])) {
            $specs[] = [
                'name' => __('product.warranty'),
                'value' => $data['warranty'],
                'locale' => $locale,
                'key' => false,
                'filterable' => false,
                'color' => null,
            ];
        }

        if ($area = RoomArea::normalize((string) ($found['room_area'] ?? ''))) {
            $specs[] = [
                'name' => 'რეკომენდირებული ფართი',
                'value' => $area,
                'locale' => $locale,
                'key' => false,
                'filterable' => true,
                'color' => null,
            ];
        }

        foreach ([
            ['wash_load', 'kg', 'მაქსიმალური ჩატვირთვა'],
            ['total_volume', 'l', 'მთლიანი მოცულობა'],
        ] as [$field, $unit, $name]) {
            if ($value = MideaClient::normalizeCapacity((string) ($found[$field] ?? ''), $unit)) {
                $specs[] = [
                    'name' => $name,
                    'value' => $value,
                    'locale' => $locale,
                    'key' => false,
                    'filterable' => true,
                    'color' => null,
                ];
            }
        }

        /* Stock is Midea's own sheet. midea.ge marks things "not in stock"
           that it still sells -- the NY2513 radiator, a hundred on the sheet,
           has an add-to-cart button and in_stock "0" -- and taking its word
           left such products at nothing, so they were never brought back. */
        $stock = max(0, (int) $row->quantity);

        return new ProductPayload(
            externalId: $barcode,

            sku: $this->supplier->code.'-'.$barcode,
            costPrice: $price,
            oldCostPrice: $old,
            stock: $stock,
            brandName: $found['brand'] ?? 'Midea',
            categoryName: $data['category'] ?? null,
            translations: [$locale => [
                'name' => $found['name'],
                'summary' => $data['description'] ?? null,
                'description' => $found['description'] ?? $data['description'] ?? null,
            ]],
            specs: $specs,
            images: $found['images'] ?? [],
            weight: $found['weight'] ?? null,
        );
    }

    protected function prices(array $found, array $data, SupplierStock $row): array
    {
        if (($found['price'] ?? 0) > 0) {
            return $found['sale_price']
                ? [(float) $found['sale_price'], (float) $found['price']]
                : [(float) $found['price'], null];
        }

        $dealer = $this->money($data['cost'] ?? null) ?? (float) $row->cost_price;
        $retailSale = $this->money($data['retail_sale'] ?? null);
        $retail = $this->money($data['retail'] ?? null);

        if (($this->supplier->config['price_from'] ?? 'dealer') === 'retail') {
            return [$retailSale ?: $retail ?: $dealer, $retailSale && $retail > $retailSale ? $retail : null];
        }

        return [$dealer, null];
    }

    protected function money(?string $text): ?float
    {
        return Figure::money($text);
    }
}
