<?php

namespace App\Services\Import;

use App\Jobs\ImportProductJob;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ImportManager
{
    protected array $drivers = [];

    public function __construct(protected ProductImporter $importer) {}

    public function driver(Supplier $supplier): SupplierDriver
    {
        if (! class_exists($supplier->driver)) {
            throw new InvalidArgumentException("Driver [{$supplier->driver}] does not exist.");
        }

        return $this->drivers[$supplier->id] ??= new $supplier->driver($supplier);
    }

    public function run(Supplier $supplier): int
    {
        $empty = $this->settleEmptyRows($supplier);
        $queued = 0;

        foreach ($this->driver($supplier)->ids() as $externalId) {
            if (isset($empty[(string) $externalId])) {
                continue;
            }

            ImportProductJob::dispatch($supplier->id, (string) $externalId)
                ->onQueue('import:'.$supplier->code);

            $queued++;
        }

        $supplier->update(['last_run_at' => now()]);

        return $queued;
    }

    protected function settleEmptyRows(Supplier $supplier): array
    {
        $empty = SupplierStock::where('supplier_id', $supplier->id)
            ->where('quantity', '<=', 0)
            ->pluck('external_id');

        if ($empty->isEmpty()) {
            return [];
        }

        $settled = 0;

        foreach ($empty->chunk(500) as $chunk) {
            $offers = ProductOffer::where('supplier_id', $supplier->id)
                ->whereIn('external_id', $chunk)
                ->where('stock', '>', 0)
                ->get(['id', 'product_id']);

            if ($offers->isEmpty()) {
                continue;
            }

            ProductOffer::whereIn('id', $offers->pluck('id'))
                ->update(['stock' => 0, 'synced_at' => now()]);

            foreach (Product::whereIn('id', $offers->pluck('product_id')->unique())->get() as $product) {
                $this->importer->refreshFromOffers($product);
            }

            $settled += $offers->count();
        }

        Log::channel('import')->info('empty rows settled without fetching', [
            'supplier' => $supplier->code,
            'rows' => $empty->count(),
            'offers_zeroed' => $settled,
        ]);

        return $empty->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }
}
