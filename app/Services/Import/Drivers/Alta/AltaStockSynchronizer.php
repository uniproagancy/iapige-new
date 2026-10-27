<?php

namespace App\Services\Import\Drivers\Alta;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class AltaStockSynchronizer
{
    public function sync(Supplier $supplier, ?AltaDriver $driver = null): array
    {
        $driver ??= new AltaDriver($supplier);
        $rows = $driver->stockRows();

        return DB::transaction(function () use ($supplier, $driver, $rows) {
            $driver->refreshStock();
            $changed = 0;
            $products = [];

            foreach (ProductOffer::where('supplier_id', $supplier->id)->lazyById(200) as $offer) {
                $quantity = max(0, (int) ($rows[$offer->external_id]['quantity'] ?? 0));

                if ($offer->stock !== $quantity) {
                    $offer->update(['stock' => $quantity, 'synced_at' => now()]);
                    $products[$offer->product_id] = true;
                    $changed++;
                }
            }

            foreach (Product::whereIn('id', array_keys($products))->get() as $product) {
                $quantity = (int) $product->offers()->whereHas('supplier', fn ($query) => $query->where('is_active', true))->sum('stock');
                // Stock refresh must never replace retail prices with B2B prices.
                $product->update(['stock' => $quantity]);
            }

            return ['rows' => count($rows), 'offers_changed' => $changed, 'products_changed' => count($products)];
        });
    }
}
