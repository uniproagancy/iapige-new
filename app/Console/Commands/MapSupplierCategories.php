<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Support\Slug;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | A price list names its own sections. Until somebody pairs each of them with
 | a shelf in the shop, everything it brings in lands without a category. For a
 | list with hundreds of sections that is an afternoon of clicking, so this
 | builds the shop's shelves from the list itself, named exactly as it names
 | them.
 */
class MapSupplierCategories extends Command
{
    protected $signature = 'import:map-categories
        {supplier : the supplier code}
        {--parent= : put the new shelves under this category id}
        {--dry : show what would be built and stop}
        {--hidden : create them switched off, to be checked first}
        {--no-fill : do not place products that are already imported}
        {--no-create : build nothing; leave unknown sections for a human to pair}';

    protected $description = "Build the shop's categories from a supplier's own sections, and pair them up";

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

        $parent = $this->option('parent');

        if ($parent && ! Category::whereKey($parent)->exists()) {
            $this->error("No category with the id [{$parent}].");

            return self::FAILURE;
        }

        if (! $this->ready()) {
            return self::FAILURE;
        }

        $names = $this->sections($supplier);

        if (! $names) {
            $this->info('This price list names no sections.');

            return self::SUCCESS;
        }

        $mapped = DB::table('supplier_category_map')
            ->where('supplier_id', $supplier->id)
            ->whereNotNull('category_id')
            ->pluck('category_id', 'external_name');

        $dry = (bool) $this->option('dry');

        $made = 0;
        $paired = 0;
        $already = 0;
        $waiting = 0;
        $rows = [];

        foreach ($names as $name => $count) {
            if (isset($mapped[$name])) {
                $already++;

                continue;
            }

            $category = $this->existing($name);

            if (! $category && $this->option('no-create')) {
                /* Parked with no shelf of its own: it shows up on the mapping
                   page, for a human to pair with a shelf they choose. */
                if (! $dry) {
                    $this->park($supplier, $name);
                }

                $waiting++;

                if (count($rows) < 15) {
                    $rows[] = [$name, $count, 'left for you to pair'];
                }

                continue;
            }

            $action = $category ? 'paired with an existing shelf' : 'a new shelf';

            if (! $category && ! $dry) {
                $category = $this->make($name, $parent, $supplier);
            }

            if (! $dry) {
                DB::table('supplier_category_map')->updateOrInsert(
                    ['supplier_id' => $supplier->id, 'external_name' => $name],
                    ['category_id' => $category?->id, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            $category && $action === 'paired with an existing shelf' ? $paired++ : $made++;

            if (count($rows) < 15) {
                $rows[] = [$name, $count, $action];
            }
        }

        if ($rows) {
            $this->table(['section', 'rows', 'what happens'], $rows);

            if (count($names) - $already > count($rows)) {
                $this->line('  … and '.(count($names) - $already - count($rows)).' more.');
            }
        }

        $this->newLine();
        $this->line('  sections in the list: '.count($names));
        $this->line("  already paired:       {$already}");
        $this->line("  paired with existing: {$paired}");
        $this->line(($dry ? '  would be created:     ' : '  created:              ')."{$made}");

        if ($waiting) {
            $this->line("  left for you to pair:  {$waiting}");
        }

        if ($dry) {
            $this->newLine();
            $this->info('Nothing was written. Run it without --dry to apply.');

            return self::SUCCESS;
        }

        /* Products imported before the shelves existed are sitting without
           one; now that the pairing is made they can simply be placed. */
        if (! $this->option('no-fill')) {
            $placed = $this->place($supplier);

            $this->line("  products placed:      {$placed}");
        }

        return self::SUCCESS;
    }

    /* The sections the list itself uses, busiest first. */
    /*
     | A price list may name its sections in a column of its own, or not name
     | them at all — in which case the source page does, and the import leaves
     | each name parked here waiting for a shelf. Both are read.
     */
    protected function sections(Supplier $supplier): array
    {
        $names = [];

        SupplierStock::where('supplier_id', $supplier->id)
            ->select(['id', 'data'])
            ->lazyById(500)
            ->each(function (SupplierStock $row) use (&$names) {
                $name = $this->sectionOf((array) ($row->data ?? []));

                if ($name !== '') {
                    $names[$name] = ($names[$name] ?? 0) + 1;
                }
            });

        DB::table('supplier_category_map')
            ->where('supplier_id', $supplier->id)
            ->whereNull('category_id')
            ->pluck('hits', 'external_name')
            ->each(function ($hits, $name) use (&$names) {
                $name = trim((string) $name);

                if ($name !== '' && ! isset($names[$name])) {
                    $names[$name] = (int) $hits;
                }
            });

        arsort($names);

        return $names;
    }

    /* The section a row belongs to, whichever of the two named it. */
    protected function sectionOf(array $data): string
    {
        return trim((string) ($data['category'] ?? $data['page_category'] ?? ''));
    }

    protected function place(Supplier $supplier): int
    {
        $pairs = DB::table('supplier_category_map')
            ->where('supplier_id', $supplier->id)
            ->whereNotNull('category_id')
            ->pluck('category_id', 'external_name');

        if ($pairs->isEmpty()) {
            return 0;
        }

        $byName = [];

        SupplierStock::where('supplier_id', $supplier->id)
            ->select(['id', 'external_id', 'data'])
            ->lazyById(500)
            ->each(function (SupplierStock $row) use (&$byName) {
                $name = $this->sectionOf((array) ($row->data ?? []));

                if ($name !== '') {
                    $byName[$name][] = $row->external_id;
                }
            });

        $placed = 0;

        foreach ($pairs as $name => $categoryId) {
            foreach (array_chunk($byName[$name] ?? [], 500) as $chunk) {
                $products = DB::table('product_offers')
                    ->where('supplier_id', $supplier->id)
                    ->whereIn('external_id', $chunk)
                    ->pluck('product_id');

                /* A shelf somebody set by hand is theirs to keep. */
                $placed += Product::whereIn('id', $products)
                    ->whereNull('category_id')
                    ->where('taxonomy_lock', false)
                    ->update(['category_id' => $categoryId]);
            }
        }

        return $placed;
    }

    protected function park(Supplier $supplier, string $name): void
    {
        DB::table('supplier_category_map')->updateOrInsert(
            ['supplier_id' => $supplier->id, 'external_name' => $name],
            ['updated_at' => now(), 'created_at' => now()],
        );
    }

    protected function existing(string $name): ?Category
    {
        $id = CategoryTranslation::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('category_id');

        return $id ? Category::find($id) : null;
    }

    protected function make(string $name, ?string $parent, Supplier $supplier): Category
    {
        $category = Category::create([
            'parent_id' => $parent ?: null,
            'is_active' => ! $this->option('hidden'),
            'show_in_menu' => false,
            'show_on_home' => false,
            'in_feed' => true,
            'sort_order' => 0,
        ]);

        /* Stamped, not filled: this is the import's own mark, not something a
           form should ever set. It is what lets the shelf be swept away later
           without catching the shop's own. */
        $category->forceFill(['created_by_supplier_id' => $supplier->id])->save();

        $slug = $this->freeSlug($name, $category->id);

        $category->saveTranslations([
            'ka' => ['name' => $name, 'slug' => $slug],
            'en' => ['name' => $name, 'slug' => $slug.'-en'],
        ]);

        return $category;
    }

    protected function freeSlug(string $name, int $id): string
    {
        $slug = Slug::make($name) ?: 'c';

        if (! CategoryTranslation::where('slug', $slug)->exists()) {
            return $slug;
        }

        return $slug.'-'.$id;
    }
}
