<?php

namespace App\Livewire\Admin\Categories;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The category tree.
 *
 * Shown as a tree rather than a paginated list: a category only means anything
 * next to its siblings, and ordering is done by dragging rows, which needs
 * them all on one screen.
 */
class Index extends Component
{
    use WithFileUploads;

    /* ---- filters ---- */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'root', except: null)]
    public ?int $rootFilter = null;

    /** '' | 'active' | 'hidden' */
    #[Url(as: 'state', except: '')]
    public string $stateFilter = '';

    /** '' | 'empty' | 'filled' */
    #[Url(as: 'stock', except: '')]
    public string $productFilter = '';

    /** '' | 'menu' | 'home' */
    #[Url(as: 'place', except: '')]
    public string $placeFilter = '';

    /* ---- form ---- */
    public bool $showForm = false;
    public ?int $editingId = null;

    public ?int $parent_id = null;
    public bool $is_active = true;
    public bool $show_on_home = false;
    public bool $show_in_menu = false;
    public int $sort_order = 0;
    public $image = null;
    public ?string $currentImage = null;

    /** ['ka' => ['name' => …, 'slug' => …, 'description' => …], 'en' => …] */
    public array $translations = [];

    /* ---- delete ---- */
    public bool $showDelete = false;
    public ?int $deletingId = null;
    public ?string $deletingName = null;
    public int $deletingChildren = 0;
    public int $deletingProducts = 0;
    public ?int $moveTo = null;

    public function mount(): void
    {
        $this->resetForm();
    }

    /* ------------------------------------------------------------------ listing */

    public function render()
    {
        $counts = Product::selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');

        return view('livewire.admin.categories.index', [
            'tree'      => $this->tree($counts),
            'options'   => $this->options(),
            'roots'     => Category::withTranslation()->whereNull('parent_id')->orderBy('sort_order')->get(),
            'languages' => Language::active(),
            'total'     => Category::count(),
        ])->layout('layouts.admin', ['title' => __('admin.categories')]);
    }

    /**
     * The whole tree as flat rows, each carrying its depth and its own totals.
     * A branch survives the filters when it, or anything under it, matches —
     * otherwise filtering would hide the parents and orphan the results.
     *
     * @return array<int, array>
     */
    protected function tree($counts): array
    {
        $all = Category::withTranslation()->orderBy('sort_order')->orderBy('id')->get();
        $byParent = $all->groupBy('parent_id');
        $rows = [];

        $walk = function ($parentId, int $depth) use (&$walk, $byParent, $counts, &$rows): bool {
            $branchMatched = false;

            foreach ($byParent[$parentId] ?? [] as $category) {
                $position = count($rows);

                // reserve the row, then let the children decide whether it stays
                $rows[] = null;

                $childMatched = $walk($category->id, $depth + 1);
                $own = $this->matches($category, $counts);

                if (! $own && ! $childMatched) {
                    array_splice($rows, $position, 1);

                    continue;
                }

                $rows[$position] = [
                    'id'       => $category->id,
                    'name'     => $category->name,
                    'depth'    => $depth,
                    'parent'   => $category->parent_id,
                    'image'    => $category->imageUrl(),
                    'active'   => (bool) $category->is_active,
                    'system'   => (bool) ($category->is_system ?? false),
                    'home'     => (bool) $category->show_on_home,
                    'menu'     => (bool) ($category->show_in_menu ?? false),
                    'children' => ($byParent[$category->id] ?? collect())->count(),
                    'products' => (int) ($counts[$category->id] ?? 0),
                    'dimmed'   => ! $own,   // only here because a child matched
                ];

                $branchMatched = true;
            }

            return $branchMatched;
        };

        $walk(null, 0);

        return array_values(array_filter($rows));
    }

    protected function matches(Category $category, $counts): bool
    {
        if ($this->rootFilter) {
            $branch = Category::find($this->rootFilter)?->descendantAndSelfIds() ?? [];

            if (! in_array($category->id, $branch, true)) {
                return false;
            }
        }

        if ($this->search !== '' && ! str_contains(mb_strtolower((string) $category->name), mb_strtolower($this->search))) {
            return false;
        }

        if ($this->stateFilter === 'active' && ! $category->is_active) {
            return false;
        }

        if ($this->stateFilter === 'hidden' && $category->is_active) {
            return false;
        }

        $products = (int) ($counts[$category->id] ?? 0);

        if ($this->productFilter === 'empty' && $products > 0) {
            return false;
        }

        if ($this->productFilter === 'filled' && $products === 0) {
            return false;
        }

        if ($this->placeFilter === 'menu' && ! $category->show_in_menu) {
            return false;
        }

        if ($this->placeFilter === 'home' && ! $category->show_on_home) {
            return false;
        }

        return true;
    }

    /** @return array<int, array{id:int, name:string, depth:int}> */
    protected function options(): array
    {
        $byParent = Category::withTranslation()->orderBy('sort_order')->orderBy('id')->get()->groupBy('parent_id');
        $flat = [];

        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$flat) {
            foreach ($byParent[$parentId] ?? [] as $category) {
                $flat[] = ['id' => $category->id, 'name' => $category->name ?: '—', 'depth' => $depth];
                $walk($category->id, $depth + 1);
            }
        };

        $walk(null, 0);

        return $flat;
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'rootFilter', 'stateFilter', 'productFilter', 'placeFilter']);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->rootFilter || $this->stateFilter || $this->productFilter || $this->placeFilter;
    }

    /* ------------------------------------------------------------------ ordering */

    /**
     * Dragging sends the ids of one parent's children in their new order.
     * Positions are rewritten from scratch, so gaps and duplicates left by
     * earlier hand-typed numbers disappear on the first drag.
     *
     * @param  array<int, int|string>  $ids
     */
    public function reorder(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $position => $id) {
                Category::whereKey((int) $id)->update(['sort_order' => $position + 1]);
            }
        });

        $this->dispatch('toast', message: __('admin.order_saved'));
    }

    /* ------------------------------------------------------------------ form */

    public function create(?int $parentId = null): void
    {
        $this->resetForm();
        $this->parent_id = $parentId;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $category = Category::with('translations')->findOrFail($id);

        $this->editingId = $category->id;
        $this->parent_id = $category->parent_id;
        $this->is_active = $category->is_active;
        $this->show_on_home = $category->show_on_home;
        $this->show_in_menu = (bool) ($category->show_in_menu ?? false);
        $this->sort_order = $category->sort_order;
        $this->currentImage = $category->imageUrl();
        $this->image = null;
        $this->resetValidation();

        $this->translations = Language::active()->mapWithKeys(fn (Language $l) => [
            $l->code => [
                'name'        => $category->translate($l->code, false)?->name ?? '',
                'slug'        => $category->translate($l->code, false)?->slug ?? '',
                'description' => $category->translate($l->code, false)?->description ?? '',
            ],
        ])->all();

        $this->showForm = true;
    }

    public function save(): void
    {
        $default = Language::defaultCode();

        $this->validate([
            "translations.{$default}.name" => ['required', 'string', 'max:120'],
            'parent_id'                    => ['nullable', 'exists:categories,id'],
            'image'                        => ['nullable', 'image', 'max:2048'],
        ]);

        if ($this->editingId && $this->parent_id) {
            $descendants = Category::findOrFail($this->editingId)->descendantAndSelfIds();

            if (in_array($this->parent_id, $descendants, true)) {
                $this->addError('parent_id', __('admin.category_loop'));

                return;
            }
        }

        DB::transaction(function () {
            $category = $this->editingId ? Category::findOrFail($this->editingId) : new Category;

            $category->fill([
                'parent_id'    => $this->parent_id,
                'is_active'    => $this->is_active,
                'show_on_home' => $this->show_on_home,
                'show_in_menu' => $this->show_in_menu,
            ]);

            // a new category goes to the end of its level; dragging changes it afterwards
            if (! $category->exists) {
                $category->sort_order = (int) Category::where('parent_id', $this->parent_id)->max('sort_order') + 1;
            }

            if ($this->image) {
                $category->image = $this->image->store('categories', 'public');
            }

            $category->save();

            $rows = [];

            foreach ($this->translations as $locale => $fields) {
                if (empty($fields['name'])) {
                    continue;
                }

                $rows[$locale] = [
                    'name'        => $fields['name'],
                    'slug'        => $fields['slug'] ?: Slug::make($fields['name']).'-'.$category->id,
                    'description' => $fields['description'] ?? null,
                ];
            }

            $category->saveTranslations($rows);
        });

        $this->showForm = false;
        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function toggleActive(int $id): void
    {
        $category = Category::findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);
    }

    public function toggleMenu(int $id): void
    {
        $category = Category::findOrFail($id);
        $category->update(['show_in_menu' => ! $category->show_in_menu]);
    }

    public function toggleHome(int $id): void
    {
        $category = Category::findOrFail($id);
        $category->update(['show_on_home' => ! $category->show_on_home]);
    }

    /* ------------------------------------------------------------------ delete */

    public function confirmDelete(int $id): void
    {
        $category = Category::withTranslation()->withCount('children')->findOrFail($id);

        if ($category->is_system) {
            $this->dispatch('toast', message: __('admin.category_is_system'), type: 'error');

            return;
        }

        $this->deletingId = $category->id;
        $this->deletingName = $category->name;
        $this->deletingChildren = $category->children_count;
        $this->deletingProducts = Product::whereIn('category_id', $category->descendantAndSelfIds())->count();
        $this->moveTo = $category->parent_id ?? Category::fallback()->id;
        $this->resetValidation();

        $this->showDelete = true;
    }

    public function delete(): void
    {
        $category = Category::findOrFail($this->deletingId);

        if ($category->is_system) {
            $this->dispatch('toast', message: __('admin.category_is_system'), type: 'error');

            return;
        }

        $target = $this->moveTo ? Category::findOrFail($this->moveTo) : Category::fallback();

        if (in_array($target->id, $category->descendantAndSelfIds(), true)) {
            $this->addError('moveTo', __('admin.move_into_self'));

            return;
        }

        DB::transaction(function () use ($category, $target) {
            Category::where('parent_id', $category->id)->update(['parent_id' => $target->id]);
            Product::where('category_id', $category->id)->update(['category_id' => $target->id]);

            // a supplier mapping pointing here would silently refill a deleted category
            DB::table('supplier_category_map')->where('category_id', $category->id)
                ->update(['category_id' => $target->id, 'updated_at' => now()]);

            $category->delete();
        });

        $this->showDelete = false;
        $this->reset(['deletingId', 'deletingName', 'deletingChildren', 'deletingProducts', 'moveTo']);

        $this->dispatch('toast', message: __('admin.category_deleted', ['target' => $target->name]));
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->parent_id = null;
        $this->is_active = true;
        $this->show_on_home = false;
        $this->show_in_menu = false;
        $this->sort_order = 0;
        $this->image = null;
        $this->currentImage = null;
        $this->resetValidation();

        $this->translations = Language::active()
            ->mapWithKeys(fn (Language $l) => [$l->code => ['name' => '', 'slug' => '', 'description' => '']])
            ->all();
    }
}
