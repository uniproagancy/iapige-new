<?php

namespace App\Services\Import;

use App\Jobs\ImportProductJob;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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

    public function run(Supplier $supplier, ?array $only = null): int
    {
        $driver = $this->driver($supplier);

        if ($driver instanceof ChecksImportSource) {
            $driver->checkSource();
        }

        $ids = $driver->ids();
        $empty = $this->settleEmptyRows($supplier);
        $wanted = $only === null ? null : array_flip(array_map('strval', $only));
        $queued = 0;
        $pending = [];

        foreach ($ids as $externalId) {
            if (isset($empty[(string) $externalId])) {
                continue;
            }

            if ($wanted !== null && ! isset($wanted[(string) $externalId])) {
                continue;
            }

            $pending[] = new ImportProductJob($supplier->id, (string) $externalId);
            $queued++;

            if (count($pending) >= self::PUSH_AT) {
                $this->push($supplier, $pending);
                $pending = [];
            }
        }

        $this->push($supplier, $pending);

        $supplier->update(['last_run_at' => now()]);

        return $queued;
    }

    /* A price list of several thousand rows was written to the queue one
       insert at a time, from inside a web request; it ran out of time long
       before the end and left the rest unqueued, with nothing to show for it.
       A batch goes in as a single insert. */
    protected const PUSH_AT = 250;

    protected function push(Supplier $supplier, array $jobs): void
    {
        if (! $jobs) {
            return;
        }

        Queue::bulk($jobs, '', 'import:'.$supplier->code);
    }

    protected function settleEmptyRows(Supplier $supplier): array
    {
        $empty = SupplierStock::where('supplier_id', $supplier->id)
            ->where('quantity', '<=', 0)
            ->pluck('external_id');

        if ($empty->isEmpty()) {
            return [];
        }

        $settled = $this->zeroOffers($supplier, $empty->all());

        Log::channel('import')->info('empty rows settled without fetching', [
            'supplier' => $supplier->code,
            'rows' => $empty->count(),
            'offers_zeroed' => $settled,
        ]);

        return $empty->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }

    /*
     | A freshly loaded full stock file, put to the site's offers at once.
     | $gone are the codes the previous file had in stock and this one does
     | not: their rows are deleted with the old file, so no later run would
     | ever come by them to take them off sale.
     |
     | Only codes a file once carried are touched. Offers are kept under the
     | file's codes for most suppliers, but not for all, and zeroing every
     | offer the new file does not name would empty those suppliers whole.
     */
    public function applyStockFile(Supplier $supplier, array $gone = []): int
    {
        $driver = class_exists((string) $supplier->driver) ? $this->driver($supplier) : null;

        if ($driver instanceof SyncsStockFile) {
            return $driver->syncStockFile();
        }

        $empty = SupplierStock::where('supplier_id', $supplier->id)
            ->where('quantity', '<=', 0)
            ->pluck('external_id')
            ->all();

        $zeroed = $this->zeroOffers($supplier, array_merge($gone, $empty));

        if ($zeroed) {
            Log::channel('import')->info('stock file applied: offers it no longer lists set to 0', [
                'supplier' => $supplier->code,
                'offers_zeroed' => $zeroed,
            ]);
        }

        return $zeroed;
    }

    protected function zeroOffers(Supplier $supplier, array $externalIds): int
    {
        $zeroed = 0;

        foreach (array_chunk(array_values(array_unique(array_map('strval', $externalIds))), 500) as $chunk) {
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

            $zeroed += $offers->count();
        }

        return $zeroed;
    }
}
