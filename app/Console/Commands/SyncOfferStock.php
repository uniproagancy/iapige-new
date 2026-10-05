<?php

namespace App\Console\Commands;

use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductImporter;
use Illuminate\Console\Command;

/**
 * Pushes a refreshed stock feed onto the offers we already have, without
 * touching the website. Running this after import:stock keeps the shop's
 * availability current between full imports.
 */
class SyncOfferStock extends Command
{
    protected $signature = 'import:sync-stock {supplier}';

    protected $description = 'Apply the stock feed to existing offers';

    public function handle(ProductImporter $importer): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->firstOrFail();
        $changed = 0;

        ProductOffer::where('supplier_id', $supplier->id)
            ->with('product')
            ->chunkById(300, function ($offers) use ($supplier, $importer, &$changed) {
                $stock = SupplierStock::where('supplier_id', $supplier->id)
                    ->whereIn('external_id', $offers->pluck('external_id'))
                    ->pluck('quantity', 'external_id');

                foreach ($offers as $offer) {
                    $quantity = (int) ($stock[$offer->external_id] ?? 0);

                    if ($quantity === $offer->stock) {
                        continue;
                    }

                    $offer->update(['stock' => $quantity, 'synced_at' => now()]);

                    if ($offer->product) {
                        $importer->refreshFromOffers($offer->product);
                    }

                    $changed++;
                }
            });

        $this->info("{$supplier->code}: {$changed} offers updated");

        return self::SUCCESS;
    }
}
