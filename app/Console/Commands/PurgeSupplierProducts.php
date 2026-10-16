<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeSupplierProducts extends Command
{
    protected $signature = 'products:purge
                            {supplier : the supplier code, e.g. elite}
                            {--status= : only this status (draft|active|archived)}
                            {--out-of-stock : only products with nothing on the shelf}
                            {--force : actually delete; without it nothing is touched}
                            {--include-ordered : also delete products somebody has ordered}
                            {--everything : also clear the price list, the category mapping and the cached crawl}
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

        if ($mine->isEmpty() && $shared->isEmpty() && ! $this->option('everything')) {
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Nothing was deleted. Add --force to do it for real.');

            return self::SUCCESS;
        }

        $this->purge($mine);
        $this->detach($supplier, $shared);

        if ($this->option('everything')) {
            $this->forget($supplier);
        }

        $this->newLine();
        $this->info('Done. Products on the storefront now: '.Product::active()->count());

        return self::SUCCESS;
    }

    protected function forget(Supplier $supplier): void
    {
        $stocks = SupplierStock::where('supplier_id', $supplier->id)->delete();
        $mapped = DB::table('supplier_category_map')->where('supplier_id', $supplier->id)->delete();

        foreach (['site-index', 'sitemap-index'] as $name) {
            Cache::forget("import:{$supplier->code}:{$name}");
        }

        $this->line("  Cleared {$stocks} price list rows, {$mapped} mapped categories and the cached crawl.");
    }

    protected function classify(Supplier $supplier): array
    {
        $productIds = ProductOffer::where('supplier_id', $supplier->id)->pluck('product_id')->unique();

        if ($productIds->isEmpty()) {
            return [collect(), collect(), collect()];
        }

        $offerCounts = ProductOffer::whereIn('product_id', $productIds)
            ->selectRaw('product_id, count(distinct supplier_id) as suppliers')
            ->groupBy('product_id')
            ->pluck('suppliers', 'product_id');

        $orderedIds = DB::table('order_items')
            ->whereIn('product_id', $productIds)
            ->distinct()
            ->pluck('product_id');

        $status = $this->option('status');

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
            ['price list rows held', SupplierStock::where('supplier_id', $supplier->id)->count()],
            ['categories mapped', DB::table('supplier_category_map')->where('supplier_id', $supplier->id)->count()],
        ]);

        if (! $this->option('everything')) {
            $this->line('  The price list and the category mapping are kept. Use --everything to clear those too.');
        }

        if ($ordered->isNotEmpty()) {
            $this->line('  Ordered products are kept so their orders keep their link. Use --include-ordered to delete them anyway.');
        }
    }

    protected function purge($ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $bar = $this->output->createProgressBar($ids->count());
        $bar->start();

        foreach ($ids->chunk((int) $this->option('chunk')) as $chunk) {

            foreach ($chunk as $id) {
                Storage::disk('public')->deleteDirectory("products/{$id}");
            }

            Product::withTrashed()->whereIn('id', $chunk)->forceDelete();

            $bar->advance($chunk->count());
        }

        $bar->finish();
        $this->newLine();
    }

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
