<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Services\Import\ProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes what a supplier put in the catalogue.
 *
 * A bad run can leave thousands of products nobody wants, and taking them out
 * through the product list means selecting them a page at a time. This does it
 * in one pass, and takes their specs, images, offers, translations and filter
 * links with them — a soft delete leaves all of that behind, still counted in
 * every filter.
 *
 * Nothing is removed without --force. The default is a report.
 */
class PurgeSupplierProducts extends Command
{
    protected $signature = 'products:purge
                            {supplier : the supplier code, e.g. elite}
                            {--status= : only this status (draft|active|archived)}
                            {--out-of-stock : only products with nothing on the shelf}
                            {--force : actually delete; without it nothing is touched}
                            {--include-ordered : also delete products somebody has ordered}
                            {--chunk=200}';

    protected $description = 'Delete the products a supplier added, with everything attached to them';

    public function handle(): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->first();

        if (! $supplier) {
            $this->error("No supplier with code [{$this->argument('supplier')}].");

            return self::FAILURE;
        }

        [$mine, $shared, $ordered] = $this->classify($supplier);

        $this->report($supplier, $mine, $shared, $ordered);

        if ($mine->isEmpty() && $shared->isEmpty()) {
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Nothing was deleted. Add --force to do it for real.');

            return self::SUCCESS;
        }

        $this->purge($mine);
        $this->detach($supplier, $shared);

        $this->newLine();
        $this->info('Done. Products on the storefront now: '.Product::active()->count());

        return self::SUCCESS;
    }

    /**
     * Splits the supplier's products three ways.
     *
     * A product another supplier also sells is not this supplier's to delete —
     * only its offer is, and the price then comes from whoever is left. Getting
     * that wrong takes a live product off the shop because a different supplier
     * had a bad import.
     *
     * @return array{0: Collection, 1: Collection, 2: Collection}
     */
    protected function classify(Supplier $supplier): array
    {
        $productIds = ProductOffer::where('supplier_id', $supplier->id)->pluck('product_id')->unique();

        if ($productIds->isEmpty()) {
            return [collect(), collect(), collect()];
        }

        // how many suppliers sell each of them, this one included
        $offerCounts = ProductOffer::whereIn('product_id', $productIds)
            ->selectRaw('product_id, count(distinct supplier_id) as suppliers')
            ->groupBy('product_id')
            ->pluck('suppliers', 'product_id');

        $orderedIds = DB::table('order_items')
            ->whereIn('product_id', $productIds)
            ->distinct()
            ->pluck('product_id');

        $status = $this->option('status');

        /*
         * The stock filter is for clearing up after a rule arrives late.
         * Zoommer now refuses to add a product it cannot hand over, but
         * everything imported before that is still in the catalogue with
         * nothing behind it.
         */
        $eligible = Product::withTrashed()
            ->whereIn('id', $productIds)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($this->option('out-of-stock'), fn ($q) => $q->where('stock', '<=', 0))
            ->pluck('id');

        $mine = collect();
        $shared = collect();
        $ordered = collect();

        foreach ($eligible as $id) {
            if ((int) ($offerCounts[$id] ?? 1) > 1) {
                $shared->push($id);

                continue;
            }

            // an ordered product is somebody's receipt; left alone unless asked
            if ($orderedIds->contains($id) && ! $this->option('include-ordered')) {
                $ordered->push($id);

                continue;
            }

            $mine->push($id);
        }

        return [$mine, $shared, $ordered];
    }

    protected function report(Supplier $supplier, $mine, $shared, $ordered): void
    {
        $this->newLine();
        $this->line("Supplier: <options=bold>{$supplier->code}</>");

        $this->table(['what', 'count'], [
            ['products only this supplier sells (will be deleted)', $mine->count()],
            ['products another supplier also sells (offer dropped only)', $shared->count()],
            ['products somebody has ordered (kept)', $ordered->count()],
            ['specs attached', DB::table('product_specs')->whereIn('product_id', $mine)->count()],
            ['images attached', DB::table('product_images')->whereIn('product_id', $mine)->count()],
            ['filter links attached', DB::table('attribute_value_product')->whereIn('product_id', $mine)->count()],
        ]);

        if ($ordered->isNotEmpty()) {
            $this->line('  Ordered products are kept so their orders keep their link. Use --include-ordered to delete them anyway.');
        }
    }

    /**
     * Hard delete, so the database takes the children with it.
     *
     * Every child table is declared cascadeOnDelete, which a soft delete never
     * triggers: the specs, images and filter links of a "deleted" product go on
     * existing and go on being counted by every facet on the catalogue page.
     * order_items is nullOnDelete, so a past order keeps its own copy of the
     * name and price and only loses the link.
     */
    protected function purge($ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $bar = $this->output->createProgressBar($ids->count());
        $bar->start();

        foreach ($ids->chunk((int) $this->option('chunk')) as $chunk) {
            // the folder is named after the product, so it goes before the row
            foreach ($chunk as $id) {
                Storage::disk('public')->deleteDirectory("products/{$id}");
            }

            Product::withTrashed()->whereIn('id', $chunk)->forceDelete();

            $bar->advance($chunk->count());
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * For a product somebody else also sells, only the offer goes.
     *
     * The price is then re-derived from the offers that remain, or the shop
     * would keep quoting a supplier it no longer buys from.
     */
    protected function detach(Supplier $supplier, $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        ProductOffer::where('supplier_id', $supplier->id)->whereIn('product_id', $ids)->delete();

        $importer = app(ProductImporter::class);

        foreach (Product::withTrashed()->whereIn('id', $ids)->cursor() as $product) {
            $importer->refreshFromOffers($product);
        }

        $this->line("  Dropped this supplier's offer from {$ids->count()} shared products and repriced them.");
    }
}
