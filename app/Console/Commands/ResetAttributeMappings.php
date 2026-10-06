<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Supplier;
use App\Support\Slug;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Clears the mappings the importer made for itself, so a person can make them.
 *
 * Unknown attributes are created and mapped automatically, which keeps specs
 * from being lost but fills the taxonomy with one attribute per supplier's
 * wording. Starting the mapping over by hand means putting those rows back to
 * unmapped and taking the invented attributes out.
 *
 * Nothing happens without --force. The default is a report.
 */
class ResetAttributeMappings extends Command
{
    protected $signature = 'attributes:reset
                            {--supplier= : only this supplier code}
                            {--category= : only attributes used by products in this category (id or slug)}
                            {--only-exclusive : with --category, skip attributes other categories also use}
                            {--keep-attributes : clear the mappings but leave the attributes and their specs}
                            {--force : actually do it}';

    protected $description = 'Put supplier attribute mappings back to unmapped, ready to be mapped by hand';

    /** Attributes the chosen category shares with others, for the report. */
    protected $shared;

    public function handle(): int
    {
        $supplier = null;

        if ($code = $this->option('supplier')) {
            $supplier = Supplier::where('code', $code)->first();

            if (! $supplier) {
                $this->error("No supplier with code [{$code}].");

                return self::FAILURE;
            }
        }

        $category = null;

        if ($name = $this->option('category')) {
            $category = $this->findCategory($name);

            if (! $category) {
                $this->error("No category matching [{$name}].");

                return self::FAILURE;
            }
        }

        $rows = DB::table('supplier_attribute_map')
            ->when($supplier, fn ($q) => $q->where('supplier_id', $supplier->id))
            ->when($category, fn ($q) => $q->whereIn('attribute_id', $this->attributesOf($category)))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No attribute mappings to reset.');

            return self::SUCCESS;
        }

        $invented = $this->option('keep-attributes') ? collect() : $this->inventedBy($rows);

        $this->report($rows, $invented);

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Nothing was changed. Add --force to do it for real.');

            return self::SUCCESS;
        }

        DB::table('supplier_attribute_map')->whereIn('id', $rows->pluck('id'))
            ->update(['attribute_id' => null, 'updated_at' => now()]);

        if ($invented->isNotEmpty()) {
            // cascades to attribute_translations, attribute_values, product_specs
            Attribute::whereIn('id', $invented->pluck('id'))->delete();
        }

        $this->newLine();
        $this->info('Done. Every one of those names is now unmapped.');
        $this->warnAboutTheNextRun();

        return self::SUCCESS;
    }

    protected function findCategory(string $name): ?Category
    {
        return ctype_digit($name)
            ? Category::find((int) $name)
            : Category::whereTranslation('slug', $name, false)->first();
    }

    /**
     * The attributes a category's products actually carry.
     *
     * Nothing ties an attribute to a category directly — the only path is
     * category → products → specs → attribute, so "this category's attributes"
     * means the ones its products happen to use.
     *
     * Which is why the overlap is reported rather than glossed over: a mapping
     * row is (supplier, name) => attribute for the whole shop, so unmapping
     * "მწარმოებელი" because the phones use it unmaps it for the televisions
     * too. --only-exclusive narrows this to the attributes nothing else uses.
     *
     * @return Collection<int, int>
     */
    protected function attributesOf(Category $category): Collection
    {
        $ids = $category->descendantAndSelfIds();

        $used = DB::table('product_specs')
            ->join('products', 'products.id', '=', 'product_specs.product_id')
            ->whereIn('products.category_id', $ids)
            ->distinct()
            ->pluck('attribute_id');

        $this->shared = DB::table('product_specs')
            ->join('products', 'products.id', '=', 'product_specs.product_id')
            ->whereIn('attribute_id', $used)
            ->whereNotIn('products.category_id', $ids)
            ->distinct()
            ->pluck('attribute_id');

        return $this->option('only-exclusive')
            ? $used->diff($this->shared)->values()
            : $used;
    }

    /**
     * The attributes the importer invented for these names.
     *
     * There is no flag saying so, but there is something better: the code an
     * invented attribute carries is produced from the supplier's own wording by
     * a function with no choices in it, so running the wording through the same
     * rule names the attribute it would have created. An attribute whose code
     * matches nothing in the mapping table was made by a person and is left
     * alone.
     *
     * @param  Collection  $rows
     */
    protected function inventedBy($rows): Collection
    {
        $codes = $rows->pluck('external_name')->map(fn ($name) => $this->codeFor($name))->unique();

        return Attribute::whereIn('code', $codes)->get(['id', 'code']);
    }

    /** The same rule TaxonomyResolver uses, so the two cannot disagree. */
    protected function codeFor(string $name): string
    {
        $code = Slug::make($name) ?: Str::slug($name);

        return $code !== '' ? Str::limit($code, 60, '') : 'spec-'.substr(md5($name), 0, 8);
    }

    protected function report($rows, $invented): void
    {
        $specs = DB::table('product_specs')->whereIn('attribute_id', $invented->pluck('id'))->count();
        $values = DB::table('attribute_values')->whereIn('attribute_id', $invented->pluck('id'))->count();

        $this->newLine();
        $this->table(['what', 'count'], [
            ['mappings set back to unmapped', $rows->count()],
            ['attributes the importer invented (deleted)', $invented->count()],
            ['product specs that go with them', $specs],
            ['filter options that go with them', $values],
            ['attributes left untouched', Attribute::count() - $invented->count()],
        ]);

        if ($specs) {
            $this->line('  Those specs are gone until the products are imported again.');
            $this->line('  To keep them and only clear the mappings, use --keep-attributes.');
        }

        if ($this->option('category') && ! $this->option('only-exclusive') && filled($this->shared)) {
            $this->newLine();
            $this->warn($this->shared->count().' of these attributes are used by other categories too.');
            $this->line('  A mapping is one row for the whole shop, so those lose their mapping');
            $this->line('  everywhere, not only here. Use --only-exclusive to leave them alone.');
        }
    }

    /**
     * The one thing that makes this pointless if nobody says it.
     *
     * TaxonomyResolver maps a name it finds unmapped by creating an attribute
     * for it, which is the behaviour this command just undid — so a run started
     * before the mapping is done puts every invented attribute straight back.
     */
    protected function warnAboutTheNextRun(): void
    {
        $this->newLine();
        $this->warn('Map these names in the admin BEFORE the next import.');
        $this->line('  An import invents an attribute for any name it still finds unmapped,');
        $this->line('  so running it first would undo this. A name you have mapped is respected.');
        $this->line('  Admin → Mapping → attributes, then: php artisan queue:restart');
    }
}
