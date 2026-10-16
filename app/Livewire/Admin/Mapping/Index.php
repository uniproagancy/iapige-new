<?php

namespace App\Livewire\Admin\Mapping;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'tab', except: 'categories')]
    public string $tab = 'categories';

    public ?int $picking = null;

    public string $pick = '';

    protected const SUGGESTIONS = 12;

    #[Url(as: 'supplier', except: null)]
    public ?int $supplierId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'state', except: '')]
    public string $stateFilter = '';

    public int $perPage = 40;

    protected const CONTEXT_CATEGORIES = 3;

    public array $choice = [];

    public bool $showImpact = true;

    protected const STATES = ['', 'mapped', 'unmapped'];

    public function mount(): void
    {
        if (! in_array($this->stateFilter, self::STATES, true)) {
            $this->stateFilter = '';
        }
    }

    public function updated($property): void
    {
        if (in_array($property, ['tab', 'supplierId', 'search', 'stateFilter'], true)) {
            $this->resetPage();
            $this->choice = [];
        }
    }

    protected function table(): string
    {
        return $this->tab === 'attributes' ? 'supplier_attribute_map' : 'supplier_category_map';
    }

    protected function column(): string
    {
        return $this->tab === 'attributes' ? 'attribute_id' : 'category_id';
    }

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
            'rows' => $rows,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'targets' => $this->targets(),
            'pending' => $this->pendingCounts(),
            'impact' => $this->showImpact ? $this->impact($rows) : [],
            'context' => $this->categoryContext($rows),
            'canRelink' => $this->canRelink(),

            'suggestions' => $this->picking ? $this->suggestions() : [],
        ])->layout('layouts.admin', ['title' => __('admin.mapping')]);
    }

    protected function targets(): array
    {
        if ($this->tab === 'attributes') {
            return Attribute::withTranslation()->orderBy('sort_order')->get()
                ->mapWithKeys(fn (Attribute $a) => [$a->id => ($a->name ?: $a->code).' · '.$a->code])
                ->all();
        }

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

    protected function categoryContext($rows): array
    {
        if ($this->tab !== 'attributes') {
            return [];
        }

        $attributeIds = collect($rows->items())->pluck('attribute_id')->filter()->unique();

        if ($attributeIds->isEmpty()) {
            return [];
        }

        $counts = DB::table('product_specs as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->whereIn('ps.attribute_id', $attributeIds)
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.category_id')
            ->selectRaw('ps.attribute_id, p.category_id, count(distinct p.id) as products')
            ->groupBy('ps.attribute_id', 'p.category_id')
            ->orderByDesc('products')
            ->get();

        $names = Category::withTranslation()
            ->whereIn('id', $counts->pluck('category_id')->unique())
            ->get()
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->name ?: '#'.$c->id]);

        return $counts
            ->groupBy('attribute_id')
            ->map(fn ($group) => $group

                ->take(self::CONTEXT_CATEGORIES)
                ->map(fn ($row) => [
                    'name' => $names[$row->category_id] ?? '—',
                    'products' => (int) $row->products,
                ])
                ->values()
                ->all())
            ->all();
    }

    protected function pendingCounts(): array
    {
        return [
            'categories' => DB::table('supplier_category_map')->whereNull('category_id')->count(),
            'attributes' => DB::table('supplier_attribute_map')->whereNull('attribute_id')->count(),
        ];
    }

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

    protected function canRelink(): bool
    {
        return Schema::hasColumn('product_offers', 'external_category');
    }

    public function openPicker(int $id): void
    {
        $this->picking = $id;
        $this->pick = '';
    }

    public function closePicker(): void
    {
        $this->picking = null;
        $this->pick = '';
    }

    public function choose(int $id, int $targetId): void
    {
        $this->assign($id, $targetId);
        $this->closePicker();
    }

    public function unmap(int $id): void
    {
        $this->assign($id, null);
        $this->closePicker();
    }

    public function suggestions(): array
    {
        $targets = $this->targets();
        $query = trim($this->pick);

        if ($query !== '') {
            $targets = array_filter($targets, fn (string $label) => mb_stripos($label, $query) !== false);
        }

        return array_slice($targets, 0, self::SUGGESTIONS, true);
    }

    public function assign(int $id, $targetId): void
    {
        $targetId = ($targetId === '' || $targetId === null) ? null : (int) $targetId;

        DB::table($this->table())->where('id', $id)->update([
            $this->column() => $targetId,
            'updated_at' => now(),
        ]);

        if ($targetId) {
            $this->dispatch('toast', message: __('admin.saved'));
        }
    }

    public function saveAll(): void
    {
        $saved = 0;

        foreach ($this->choice as $id => $targetId) {
            if ($targetId === '' || $targetId === null) {
                continue;
            }

            DB::table($this->table())->where('id', (int) $id)->update([
                $this->column() => (int) $targetId,
                'updated_at' => now(),
            ]);

            $saved++;
        }

        $this->choice = [];
        $this->dispatch('toast', message: __('admin.mapped', ['count' => $saved]));
    }

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

    public function forget(int $id): void
    {
        DB::table($this->table())->where('id', $id)->delete();
        $this->dispatch('toast', message: __('admin.deleted'));
    }
}
