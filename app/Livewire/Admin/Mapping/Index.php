<?php

namespace App\Livewire\Admin\Mapping;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Models\Supplier;
use App\Services\Import\TaxonomyResolver;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Where a supplier's own names are tied to ours.
 *
 * Nothing here guesses: an unmapped category leaves its products without one
 * until a human decides, because a wrong category is worse than none. Rows are
 * ordered by how often each name arrived, so the first ten minutes spent here
 * cover most of the catalogue.
 */
class Index extends Component
{
    use WithPagination;

    /** categories | attributes */
    #[Url(as: 'tab', except: 'categories')]
    public string $tab = 'categories';

    #[Url(as: 'supplier', except: null)]
    public ?int $supplierId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' | 'mapped' | 'unmapped' | 'new' — everything by default, unmapped first */
    #[Url(as: 'state', except: '')]
    public string $stateFilter = '';

    public int $perPage = 40;

    /** row id => target id, for the bulk save */
    public array $choice = [];

    /** how many products are waiting behind each unmapped name */
    public bool $showImpact = true;

    public function updated($property): void
    {
        if (in_array($property, ['tab', 'supplierId', 'search', 'stateFilter'], true)) {
            $this->resetPage();
            $this->choice = [];
        }
    }

    /* ------------------------------------------------------------------ shape */

    protected function table(): string
    {
        return $this->tab === 'attributes' ? 'supplier_attribute_map' : 'supplier_category_map';
    }

    protected function column(): string
    {
        return $this->tab === 'attributes' ? 'attribute_id' : 'category_id';
    }

    /* ------------------------------------------------------------------ render */

    public function render()
    {
        $rows = DB::table($this->table().' as m')
            ->join('suppliers as s', 's.id', '=', 'm.supplier_id')
            ->when($this->supplierId, fn ($q) => $q->where('m.supplier_id', $this->supplierId))
            ->when($this->search, fn ($q) => $q->where('m.external_name', 'like', "%{$this->search}%"))
            ->when($this->stateFilter === 'unmapped', fn ($q) => $q->whereNull('m.'.$this->column()))
            ->when($this->stateFilter === 'mapped', fn ($q) => $q->whereNotNull('m.'.$this->column()))
            // mapped by the importer's guess, never confirmed by anyone
            ->when($this->stateFilter === 'new' && $this->tab === 'attributes', fn ($q) => $q->where('m.is_auto', true))
            ->orderByDesc('m.hits')
            ->orderBy('m.external_name')
            ->select('m.*', 's.name as supplier_name', 's.code as supplier_code')
            ->paginate($this->perPage);

        return view('livewire.admin.mapping.index', [
            'rows' => $rows,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'targets' => $this->targets(),
            'pending' => $this->pendingCounts(),
            'impact' => $this->showImpact ? $this->impact($rows) : [],
            'canRelink' => $this->canRelink(),
        ])->layout('layouts.admin', ['title' => __('admin.mapping')]);
    }

    /** What a row can be mapped onto: id => readable path. */
    protected function targets(): array
    {
        if ($this->tab === 'attributes') {
            return Attribute::withTranslation()->orderBy('sort_order')->get()
                ->mapWithKeys(fn (Attribute $a) => [$a->id => ($a->name ?: $a->code).' · '.$a->code])
                ->all();
        }

        // the full path, so two different "Cases" are told apart at a glance
        $byParent = Category::withTranslation()->orderBy('sort_order')->orderBy('id')->get()->groupBy('parent_id');
        $flat = [];

        $walk = function ($parentId, string $prefix) use (&$walk, $byParent, &$flat) {
            foreach ($byParent[$parentId] ?? [] as $category) {
                $label = $prefix === '' ? (string) $category->name : $prefix.' › '.$category->name;
                $flat[$category->id] = $label;
                $walk($category->id, $label);
            }
        };

        $walk(null, '');

        return $flat;
    }

    protected function pendingCounts(): array
    {
        return [
            'categories' => DB::table('supplier_category_map')->whereNull('category_id')->count(),
            'attributes' => DB::table('supplier_attribute_map')->whereNull('attribute_id')->count(),
            // new parameters: mapped automatically, not yet looked at
            'attributes_new' => DB::table('supplier_attribute_map')->where('is_auto', true)->count(),
        ];
    }

    /**
     * How many products each unmapped category name is holding up — the number
     * that turns "47 names to map" into "these three are worth doing first".
     *
     * @return array<string, int> external_name => products
     */
    protected function impact($rows): array
    {
        if ($this->tab !== 'categories' || ! $this->canRelink()) {
            return [];
        }

        $names = collect($rows->items())->pluck('external_name')->all();

        if (! $names) {
            return [];
        }

        return DB::table('product_offers')
            ->whereIn('external_category', $names)
            ->when($this->supplierId, fn ($q) => $q->where('supplier_id', $this->supplierId))
            ->selectRaw('external_category, count(distinct product_id) as n')
            ->groupBy('external_category')
            ->pluck('n', 'external_category')
            ->all();
    }

    /** Re-linking needs the supplier's own category name kept on the offer. */
    protected function canRelink(): bool
    {
        return Schema::hasColumn('product_offers', 'external_category');
    }

    /* ------------------------------------------------------------------ actions */

    /** Map one row; the choice applies the moment it is made. */
    public function assign(int $id, $targetId): void
    {
        $targetId = ($targetId === '' || $targetId === null) ? null : (int) $targetId;

        $moved = $this->tab === 'attributes' ? $this->moveSpecs($id, $targetId) : 0;

        DB::table($this->table())->where('id', $id)->update(array_filter([
            $this->column() => $targetId,
            'updated_at' => now(),
            // a person has now decided, so it is no longer the importer's guess
            'is_auto' => $this->tab === 'attributes' ? false : null,
        ], fn ($v) => $v !== null));

        if ($moved) {
            $this->dispatch('toast', message: __('admin.specs_moved', ['count' => $moved]));

            return;
        }

        if ($targetId) {
            $this->dispatch('toast', message: __('admin.saved'));
        }
    }

    /** Apply every choice ticked in the list at once. */
    public function saveAll(): void
    {
        $saved = 0;
        $moved = 0;

        foreach ($this->choice as $id => $targetId) {
            if ($targetId === '' || $targetId === null) {
                continue;
            }

            if ($this->tab === 'attributes') {
                $moved += $this->moveSpecs((int) $id, (int) $targetId);
            }

            DB::table($this->table())->where('id', (int) $id)->update(array_filter([
                $this->column() => (int) $targetId,
                'updated_at' => now(),
                'is_auto' => $this->tab === 'attributes' ? false : null,
            ], fn ($v) => $v !== null));

            $saved++;
        }

        $this->choice = [];
        $this->dispatch('toast', message: $moved
            ? __('admin.mapped_and_moved', ['count' => $saved, 'specs' => $moved])
            : __('admin.mapped', ['count' => $saved]));
    }

    /**
     * Moves existing specs when an attribute mapping is re-pointed.
     *
     * Categories had relink and attributes had nothing: changing "მწარმოებელი"
     * to our own brand attribute only affected products imported afterwards,
     * while everything already in the catalogue stayed on the attribute the
     * importer had invented — so the old one lingered in the filters and the
     * same parameter existed twice.
     *
     * The attribute the importer invented for a name is found by running that
     * name through the resolver's own code function rather than by storing the
     * previous id, so the two can never disagree about what it was.
     *
     * Only this supplier's products move. Another supplier may legitimately map
     * the same wording somewhere else.
     *
     * @return int specs moved
     */
    protected function moveSpecs(int $rowId, ?int $targetId): int
    {
        $row = DB::table('supplier_attribute_map')->where('id', $rowId)->first();

        if (! $targetId || ! $row || (int) $row->attribute_id === $targetId) {
            return 0;   // nothing assigned, or assigned where it already was
        }

        $from = Attribute::where('code', app(TaxonomyResolver::class)->attributeCode($row->external_name))
            ->orWhere('id', $row->attribute_id)
            ->pluck('id')
            ->reject(fn ($id) => (int) $id === $targetId);

        if ($from->isEmpty()) {
            return 0;
        }

        $products = Product::whereHas('offers', fn ($q) => $q->where('supplier_id', $row->supplier_id))
            ->pluck('id');

        if ($products->isEmpty()) {
            return 0;
        }

        $moved = 0;

        /*
         * One product at a time, because (product_id, attribute_id) is unique:
         * a product that already carries the target attribute cannot take a
         * second row, and the supplier's own value is the one to keep.
         */
        foreach (ProductSpec::whereIn('product_id', $products)->whereIn('attribute_id', $from)->get() as $spec) {
            $taken = ProductSpec::where('product_id', $spec->product_id)
                ->where('attribute_id', $targetId)
                ->first();

            if ($taken) {
                $taken->update(['is_key' => $spec->is_key, 'sort_order' => $spec->sort_order]);
                $spec->translations()->get()->each(
                    fn ($t) => $taken->saveTranslations([$t->locale => ['value' => $t->value]])
                );
                $spec->delete();
            } else {
                $spec->update(['attribute_id' => $targetId]);
            }

            $moved++;
        }

        return $moved;
    }

    /**
     * Push the mapping onto products that arrived before it existed.
     * Products whose category was set by hand are left alone.
     */
    public function relink(): void
    {
        if ($this->tab !== 'categories') {
            return;
        }

        if (! $this->canRelink()) {
            $this->dispatch('toast', message: __('admin.relink_unavailable'), type: 'error');

            return;
        }

        $map = DB::table('supplier_category_map')
            ->whereNotNull('category_id')
            ->when($this->supplierId, fn ($q) => $q->where('supplier_id', $this->supplierId))
            ->get();

        $updated = 0;

        foreach ($map as $row) {
            $updated += Product::whereNull('category_id')
                ->where('taxonomy_lock', false)
                ->whereHas('offers', fn ($q) => $q
                    ->where('supplier_id', $row->supplier_id)
                    ->where('external_category', $row->external_name))
                ->update(['category_id' => $row->category_id]);
        }

        $this->dispatch('toast', message: __('admin.relinked', ['count' => $updated]));
    }

    /**
     * Create our category straight from the supplier's name and map to it —
     * the common case when a whole section is missing from our tree.
     */
    public function createAndMap(int $id, ?int $parentId = null): void
    {
        if ($this->tab !== 'categories') {
            return;
        }

        $row = DB::table('supplier_category_map')->where('id', $id)->first();

        if (! $row) {
            return;
        }

        $category = DB::transaction(function () use ($row, $parentId) {
            $category = Category::create([
                'parent_id' => $parentId,
                'is_active' => true,
                'sort_order' => (int) Category::where('parent_id', $parentId)->max('sort_order') + 1,
            ]);

            $category->saveTranslations([
                app()->getLocale() => [
                    'name' => $row->external_name,
                    'slug' => Slug::make($row->external_name).'-'.$category->id,
                ],
            ]);

            DB::table('supplier_category_map')->where('id', $row->id)
                ->update(['category_id' => $category->id, 'updated_at' => now()]);

            return $category;
        });

        $this->dispatch('toast', message: __('admin.category_created', ['name' => $category->name ?? $row->external_name]));
    }

    /** Forget a name entirely; it returns on the next import if still in use. */
    public function forget(int $id): void
    {
        DB::table($this->table())->where('id', $id)->delete();
        $this->dispatch('toast', message: __('admin.deleted'));
    }
}
