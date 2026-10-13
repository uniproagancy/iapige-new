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
    /**
     * One driver per supplier for the life of this worker.
     *
     * A driver builds its HTTP clients in the constructor, and the import job
     * asks for one per product — so this was rebuilding the same clients, and
     * throwing away whatever they had cached in memory, thousands of times a
     * run.
     *
     * @var array<int, SupplierDriver>
     */
    protected array $drivers = [];

    public function __construct(protected ProductImporter $importer) {}

    public function driver(Supplier $supplier): SupplierDriver
    {
        if (! class_exists($supplier->driver)) {
            throw new InvalidArgumentException("Driver [{$supplier->driver}] does not exist.");
        }

        return $this->drivers[$supplier->id] ??= new $supplier->driver($supplier);
    }

    /** Queue one job per product, on the supplier's own queue. */
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

    /**
     * Settles the rows the price list already says are empty, without fetching.
     *
     * A supplier's file is mostly things they have run out of, and every one of
     * those was costing a queued job and a page fetched over the network — to
     * learn what the spreadsheet had already said, and then either write a zero
     * or refuse to add the product at all. On a nine thousand row list where
     * two thirds are empty that is six thousand requests at a second or more
     * apiece: the slowest part of a run, spent entirely on products the shop
     * was never going to sell.
     *
     * The ones we already hold still have to be settled, so their offer is
     * zeroed here in bulk and the product's own stock recomputed from whatever
     * offers remain — which keeps a product another supplier still stocks on
     * sale. The ones we do not hold need nothing at all: with require_stock on
     * they would have been refused on arrival.
     *
     * Suppliers whose stock is only knowable from their API — Zoommer and the
     * rest — have no rows here, so this costs them one query and does nothing.
     *
     * @return array<string, true> the external ids there is no point asking about
     */
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

            /*
             * The product's own stock is the sum of its offers, so it has to be
             * recomputed rather than set to zero: this supplier running out
             * does not mean the shop has.
             */
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
