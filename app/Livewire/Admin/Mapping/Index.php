<?php

namespace App\Livewire\Admin\Mapping;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
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

    /** '' | 'mapped' | 'unmapped' — everything by default, unmapped first */
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
            ->orderByDesc('m.hits')
            ->orderBy('m.external_name')
            ->select('m.*', 's.name as supplier_name', 's.code as supplier_code')
            ->paginate($this->perPage);

        return view('livewire.admin.mapping.index', [
            'rows'      => $rows,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'targets'   => $this->targets(),
            'pending'   => $this->pendingCounts(),
            'impact'    => $this->showImpact ? $this->impact($rows) : [],
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
        ];
    }

    /**
     * How many products each unmapped category name is holding up — the number
     * that turns "47 names to map" into "these three are worth doing first".
     *
     * @return array<string, int>  external_name => products
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

        DB::table($this->table())->where('id', $id)->update([
            $this->column() => $targetId,
            'updated_at'    => now(),
        ]);

        if ($targetId) {
            $this->dispatch('toast', message: __('admin.saved'));
        }
    }

    /** Apply every choice ticked in the list at once. */
    public function saveAll(): void
    {
        $saved = 0;

        foreach ($this->choice as $id => $targetId) {
            if ($targetId === '' || $targetId === null) {
                continue;
            }

            DB::table($this->table())->where('id', (int) $id)->update([
                $this->column() => (int) $targetId,
                'updated_at'    => now(),
            ]);

            $saved++;
        }

        $this->choice = [];
        $this->dispatch('toast', message: __('admin.mapped', ['count' => $saved]));
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
                'parent_id'  => $parentId,
                'is_active'  => true,
                'sort_order' => (int) Category::where('parent_id', $parentId)->max('sort_order') + 1,
            ]);

            $category->saveTranslations([
                app()->getLocale() => [
                    'name' => $row->external_name,
                    'slug' => \App\Support\Slug::make($row->external_name).'-'.$category->id,
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
