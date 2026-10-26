<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Clears a supplier out so its price list can be loaded again from nothing.
 |
 | Products are only ever soft-deleted, so an order that holds one still shows
 | what was bought, and a mistake can be undone. A product another supplier
 | also sells is never removed — only this supplier's offer on it goes, and the
 | product then takes its price from whoever is left.
 */
class PurgeSupplier extends Command
{
    protected $signature = 'import:purge
        {supplier : the supplier code}
        {--dry : show what would go and stop}
        {--pairings : clear the category pairings as well}
        {--forget : remove the supplier itself afterwards}
        {--force : do not ask}';

    protected $description = 'Remove everything one supplier brought in, so its list can be loaded afresh';

    public function handle(ProductImporter $importer): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->first();

        if (! $supplier) {
            $this->error("No supplier with the code [{$this->argument('supplier')}].");

            return self::FAILURE;
        }

        $offers = ProductOffer::where('supplier_id', $supplier->id)->get(['id', 'product_id']);
        $rows = SupplierStock::where('supplier_id', $supplier->id)->count();

        if ($offers->isEmpty() && ! $rows) {
            $this->info('There is nothing of this supplier to remove.');

            if ($this->option('forget')) {
                return $this->forget($supplier);
            }

            return self::SUCCESS;
        }

        $products = $offers->pluck('product_id')->unique();

        /* A product somebody else also sells stays; only this offer leaves. */
        $shared = ProductOffer::whereIn('product_id', $products)
            ->where('supplier_id', '!=', $supplier->id)
            ->pluck('product_id')
            ->unique();

        $going = $products->diff($shared);

        $ordered = DB::table('order_items')->whereIn('product_id', $going)->distinct()->count('product_id');

        $this->table(['', 'how many'], [
            ['rows in the price list', $rows],
            ['offers from this supplier', $offers->count()],
            ['products it alone sells', $going->count()],
            ['  of those, already ordered', $ordered],
            ['products another supplier also sells', $shared->count()],
        ]);

        $this->newLine();
        $this->line('  Products are only taken off the shop, never erased: an order that');
        $this->line('  holds one still shows it, and they can be brought back.');

        if ($this->option('dry')) {
            $this->newLine();
            $this->info('Nothing was changed. Run it without --dry to apply.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Remove all of that?', false)) {
            $this->line('Left as it was.');

            return self::SUCCESS;
        }

        ProductOffer::where('supplier_id', $supplier->id)->delete();

        Product::whereIn('id', $going)->get()->each->delete();

        /* What is left keeps selling, at whatever the others charge. */
        Product::whereIn('id', $shared)->get()->each(
            fn (Product $product) => $importer->refreshFromOffers($product),
        );

        SupplierStock::where('supplier_id', $supplier->id)->delete();

        $pairings = 0;

        if ($this->option('pairings')) {
            $pairings = DB::table('supplier_category_map')->where('supplier_id', $supplier->id)->delete();
        }

        $supplier->update(['last_run_at' => null]);

        $this->newLine();
        $this->info('Cleared.');
        $this->line('  offers removed:        '.$offers->count());
        $this->line('  products taken off:    '.$going->count());
        $this->line('  products left selling: '.$shared->count());
        $this->line('  price list rows gone:  '.$rows);

        if ($this->option('pairings')) {
            $this->line('  category pairings:     '.$pairings);
        } else {
            $this->line('  category pairings kept — pass --pairings to clear them too.');
        }

        if ($this->option('forget')) {
            $this->newLine();

            return $this->forget($supplier);
        }

        return self::SUCCESS;
    }

    /*
     | Takes the supplier off the list altogether, for an arrangement that has
     | ended. Its remaining rows go with it through the database's own cascade;
     | a shelf one of its price lists built keeps standing, with nobody named
     | as its author any more.
     */
    protected function forget(Supplier $supplier): int
    {
        $built = Schema::hasColumn('categories', 'created_by_supplier_id')
            ? DB::table('categories')->where('created_by_supplier_id', $supplier->id)->count()
            : 0;

        $name = $supplier->name;

        $supplier->delete();

        $this->info("[{$name}] is off the list.");

        if ($built) {
            $this->line('  categories it had built stay, now unclaimed: '.$built);
        }

        $this->line('  its driver class and seeder can now be deleted from the code.');

        return self::SUCCESS;
    }
}
