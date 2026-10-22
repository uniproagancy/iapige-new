<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Undoes import:map-categories. Only the shelves that command built are swept
 | away — the shop's own are left alone even when a price list is paired with
 | them, because nothing here created them.
 */
class ForgetSupplierCategories extends Command
{
    protected $signature = 'import:forget-categories
        {supplier : the supplier code}
        {--dry : show what would go and stop}
        {--keep-pairings : leave the supplier\'s section pairings in place}';

    protected $description = 'Remove the categories a price list built, and the pairings that point at them';

    /* The column arrives with a migration; without it this would read as a
       SQL error rather than as the one thing that needs doing. */
    protected function ready(): bool
    {
        if (Schema::hasColumn('categories', 'created_by_supplier_id')) {
            return true;
        }

        $this->error('The categories table has no created_by_supplier_id column yet.');
        $this->line('  Run: php artisan migrate --force');

        return false;
    }

    public function handle(): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->first();

        if (! $supplier) {
            $this->error("No supplier with the code [{$this->argument('supplier')}].");

            return self::FAILURE;
        }

        if (! $this->ready()) {
            return self::FAILURE;
        }

        $built = Category::where('created_by_supplier_id', $supplier->id)->get();

        if ($built->isEmpty()) {
            $this->info('This price list has built no categories.');

            return self::SUCCESS;
        }

        $ids = $built->pluck('id')->all();

        /* A shelf somebody has since hung another shelf under is no longer the
           import's to remove. */
        $adopted = Category::whereIn('parent_id', $ids)
            ->whereNotIn('id', $ids)
            ->pluck('parent_id')
            ->unique()
            ->all();

        $goes = array_values(array_diff($ids, $adopted));
        $products = Product::whereIn('category_id', $goes)->count();

        $this->table(
            ['', 'how many'],
            [
                ['built by this price list', count($ids)],
                ['kept, something hangs under them', count($adopted)],
                ['to be removed', count($goes)],
                ['products left without a category', $products],
                ['pairings to clear', $this->option('keep-pairings') ? 0 : DB::table('supplier_category_map')
                    ->where('supplier_id', $supplier->id)->count()],
            ],
        );

        if ($this->option('dry')) {
            $this->newLine();
            $this->info('Nothing was removed. Run it without --dry to apply.');

            return self::SUCCESS;
        }

        if (! $goes) {
            $this->info('Nothing can be removed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($goes, $supplier) {
            Product::whereIn('category_id', $goes)->update(['category_id' => null]);

            Category::whereIn('id', $goes)->delete();

            if (! $this->option('keep-pairings')) {
                DB::table('supplier_category_map')->where('supplier_id', $supplier->id)->delete();
            }
        });

        $this->newLine();
        $this->info(count($goes).' categories removed.');

        if ($products) {
            $this->warn("{$products} products are now without a category — run the import again once they are paired up.");
        }

        return self::SUCCESS;
    }
}
