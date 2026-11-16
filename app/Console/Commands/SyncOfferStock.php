<?php

namespace App\Console\Commands;

use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ImportManager;
use App\Services\Import\ProductImporter;
use App\Services\Import\SyncsStockFile;
use Illuminate\Console\Command;

class SyncOfferStock extends Command
{
    protected $signature = 'import:sync-stock {supplier}';

    protected $description = 'Apply the stock feed to existing offers';

    public function handle(ProductImporter $importer, ImportManager $imports): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->firstOrFail();
        $changed = 0;

        $driver = $imports->driver($supplier);

        /* Its offers are not kept under the file's keys: matching ids here
           would find none and empty every one of them. */
        if ($driver instanceof SyncsStockFile) {
            $this->info("{$supplier->code}: ".$driver->syncStockFile().' products updated');

            return self::SUCCESS;
        }

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
