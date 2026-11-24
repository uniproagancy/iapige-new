<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Support\Catalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 | A supplier's price floor: config.min_price, the lowest price the shop
 | will sell this supplier's goods at -- the shelf price after markup, the
 | number the customer sees. config.min_cost is the older floor on what the
 | supplier charges; ProductImporter::worthAdding() still honours both.
 |
 | The floor only stops new arrivals; what came in before it was set is
 | cleared from here.
 */
class SupplierFloor
{
    public function __construct(protected ProductImporter $importer) {}

    public static function of(Supplier $supplier): float
    {
        return (float) ($supplier->config['min_price'] ?? 0);
    }

    public function set(Supplier $supplier, float $floor): void
    {
        $config = (array) $supplier->config;

        if ($floor > 0) {
            $config['min_price'] = $floor;
        } else {
            unset($config['min_price']);
        }

        $supplier->update(['config' => $config]);
    }

    /** Offers of this supplier on products the site sells under its floor. */
    public function below(Supplier $supplier): Builder
    {
        $floor = self::of($supplier);

        return ProductOffer::query()
            ->where('supplier_id', $supplier->id)
            ->whereHas('product', fn (Builder $q) => $q->where('price', '<', $floor));
    }

    /*
     | A product another supplier also sells stays on the site: only this
     | supplier's offer goes, and the price is worked out again from the
     | offers left. A product only this supplier sells is soft-deleted, as the
     | admin's bulk delete does, so it can still be restored.
     |
     | Done in bulk: a supplier can have thousands of products under a floor
     | and a row-at-a-time loop outlives the admin request.
     |
     | @return array{deleted: int, detached: int}
     */
    public function purge(Supplier $supplier): array
    {
        $done = ['deleted' => 0, 'detached' => 0];

        if (self::of($supplier) <= 0) {
            return $done;
        }

        $offers = $this->below($supplier)->pluck('product_id', 'id');

        $shared = ProductOffer::query()
            ->whereIn('product_id', $offers->unique()->values())
            ->where('supplier_id', '!=', $supplier->id)
            ->distinct()
            ->pluck('product_id')
            ->flip();

        $solo = $offers->reject(fn ($productId) => $shared->has($productId))->unique()->values();
        $detach = $offers->filter(fn ($productId) => $shared->has($productId));

        foreach ($solo->chunk(500) as $chunk) {
            $done['deleted'] += Product::whereKey($chunk->all())->delete();
        }

        foreach ($detach->keys()->chunk(500) as $chunk) {
            ProductOffer::whereKey($chunk->all())->delete();
        }

        foreach (Product::whereKey($detach->unique()->values()->all())->get() as $product) {
            DB::transaction(fn () => $this->importer->refreshFromOffers($product));
            $done['detached']++;
        }

        /* A query delete fires no model events, so the shelf cache the
           Product::deleted hook would clear is cleared here. */
        if ($done['deleted'] > 0) {
            Catalog::forgetStocked();
        }

        Log::channel('import')->info('cleared below the supplier price floor', [
            'supplier' => $supplier->code,
            'floor' => self::of($supplier),
        ] + $done);

        return $done;
    }
}
