<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Support\Slug;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        {--hidden : create them switched off, to be checked first}';

    protected $description = "Build the shop's categories from a supplier's own sections, and pair them up";

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
        $rows = [];

        foreach ($names as $name => $count) {
            if (isset($mapped[$name])) {
                $already++;

                continue;
            }

            $category = $this->existing($name);
            $action = $category ? 'paired with an existing shelf' : 'a new shelf';

            if (! $category && ! $dry) {
                $category = $this->make($name, $parent);
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

        if ($dry) {
            $this->newLine();
            $this->info('Nothing was written. Run it without --dry to apply.');
        }

        return self::SUCCESS;
    }

    /* The sections the list itself uses, busiest first. */
    protected function sections(Supplier $supplier): array
    {
        $names = [];

        SupplierStock::where('supplier_id', $supplier->id)
            ->select(['id', 'data'])
            ->lazyById(500)
            ->each(function (SupplierStock $row) use (&$names) {
                $name = trim((string) (($row->data ?? [])['category'] ?? ''));

                if ($name !== '') {
                    $names[$name] = ($names[$name] ?? 0) + 1;
                }
            });

        arsort($names);

        return $names;
    }

    protected function existing(string $name): ?Category
    {
        $id = CategoryTranslation::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('category_id');

        return $id ? Category::find($id) : null;
    }

    protected function make(string $name, ?string $parent): Category
    {
        $category = Category::create([
            'parent_id' => $parent ?: null,
            'is_active' => ! $this->option('hidden'),
            'show_in_menu' => false,
            'show_on_home' => false,
            'in_feed' => true,
            'sort_order' => 0,
        ]);

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
