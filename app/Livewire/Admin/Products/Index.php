<?php

namespace App\Livewire\Admin\Products;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The product list: where imported products are reviewed and published.
 *
 * The filters mirror the questions actually asked of this screen — "what is
 * still draft", "what has no category", "what has no photo" — because those
 * are what stand between an import and a shop.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'cat', except: null)]
    public ?int $categoryId = null;

    #[Url(as: 'brand', except: null)]
    public ?int $brandId = null;

    #[Url(as: 'supplier', except: null)]
    public ?int $supplierId = null;

    /** '' | nocat | nobrand | nophoto | noprice | out | preorder | noweight */
    #[Url(as: 'issue', except: '')]
    public string $issue = '';

    #[Url(as: 'sort', except: 'new')]
    public string $sort = 'new';

    #[Url(as: 'per', except: 25)]
    public int $perPage = 25;

    /** @var array<int, int> ids ticked for a bulk action */
    public array $selected = [];
    public bool $selectPage = false;

    /* ---- bulk pickers ---- */
    public ?int $bulkCategory = null;
    public ?int $bulkBrand = null;

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'categoryId', 'brandId', 'supplierId', 'issue', 'sort', 'perPage'], true)) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    /* ------------------------------------------------------------------ query */

    protected function query()
    {
        return Product::withTranslation()
            ->with([
                'brand',
                'images' => fn ($q) => $q->orderBy('sort_order'),
                'category' => fn ($q) => $q->withTranslation(),
                'offers.supplier',
            ])
            ->when($this->search, function ($q) {
                $term = $this->search;

                $q->where(fn ($w) => $w
                    ->where('sku', 'like', "%{$term}%")
                    ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$term}%")));
            })
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->categoryId, fn ($q) => $q->whereIn(
                'category_id',
                Category::find($this->categoryId)?->descendantAndSelfIds() ?? [$this->categoryId],
            ))
            ->when($this->brandId, fn ($q) => $q->where('brand_id', $this->brandId))
            ->when($this->supplierId, fn ($q) => $q->whereHas('offers', fn ($o) => $o->where('supplier_id', $this->supplierId)))
            ->when($this->issue === 'nocat', fn ($q) => $q->whereNull('category_id'))
            ->when($this->issue === 'nobrand', fn ($q) => $q->whereNull('brand_id'))
            ->when($this->issue === 'nophoto', fn ($q) => $q->whereDoesntHave('images'))
            ->when($this->issue === 'noprice', fn ($q) => $q->where('price', '<=', 0))
            ->when($this->issue === 'out', fn ($q) => $q->where('stock', '<=', 0)->where('is_preorder', false))
            ->when($this->issue === 'preorder', fn ($q) => $q->where('is_preorder', true))
            ->when($this->issue === 'noweight', fn ($q) => $q->whereNull('weight'))
            ->when($this->sort === 'new', fn ($q) => $q->latest('id'))
            ->when($this->sort === 'old', fn ($q) => $q->oldest('id'))
            ->when($this->sort === 'price-asc', fn ($q) => $q->orderBy('price'))
            ->when($this->sort === 'price-desc', fn ($q) => $q->orderByDesc('price'))
            ->when($this->sort === 'stock', fn ($q) => $q->orderBy('stock'))
            ->when($this->sort === 'sales', fn ($q) => $q->orderByDesc('sales_count'))
            ->when($this->sort === 'name', fn ($q) => $q->orderBy('sku'));
    }

    public function render()
    {
        return view('livewire.admin.products.index', [
            'products'   => $this->query()->paginate($this->perPage),
            'categories' => $this->categoryOptions(),
            'brands'     => Brand::orderBy('name')->get(['id', 'name']),
            'suppliers'  => Supplier::orderBy('name')->get(['id', 'name']),
            'statuses'   => [
                Product::STATUS_DRAFT    => __('admin.status_draft'),
                Product::STATUS_ACTIVE   => __('admin.status_active'),
                Product::STATUS_ARCHIVED => __('admin.status_archived'),
            ],
            'issues'     => $this->issueCounts(),
        ])->layout('layouts.admin', ['title' => __('admin.products')]);
    }

    /** The tree as a flat list, so a sub-category is pickable and still readable. */
    protected function categoryOptions(): array
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

    /** One query for the whole problem bar, instead of seven counts. */
    protected function issueCounts(): array
    {
        $row = DB::table('products')
            ->selectRaw('
                sum(case when status = ? then 1 else 0 end) as drafts,
                sum(case when category_id is null then 1 else 0 end) as nocat,
                sum(case when brand_id is null then 1 else 0 end) as nobrand,
                sum(case when price <= 0 then 1 else 0 end) as noprice,
                sum(case when stock <= 0 and is_preorder = 0 then 1 else 0 end) as `out`,
                sum(case when weight is null then 1 else 0 end) as noweight
            ', [Product::STATUS_DRAFT])
            ->whereNull('deleted_at')
            ->first();

        return [
            'drafts'   => (int) ($row->drafts ?? 0),
            'nocat'    => (int) ($row->nocat ?? 0),
            'nobrand'  => (int) ($row->nobrand ?? 0),
            'noprice'  => (int) ($row->noprice ?? 0),
            'out'      => (int) ($row->out ?? 0),
            'noweight' => (int) ($row->noweight ?? 0),
            'nophoto'  => Product::whereDoesntHave('images')->count(),
        ];
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'categoryId', 'brandId', 'supplierId', 'issue', 'sort']);
        $this->resetPage();
        $this->clearSelection();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status || $this->categoryId
            || $this->brandId || $this->supplierId || $this->issue;
    }

    /* ------------------------------------------------------------------ selection */

    public function updatedSelectPage(bool $value): void
    {
        $this->selected = $value
            ? $this->query()->paginate($this->perPage)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    /** Everything the current filters match, not just the page in view. */
    public function selectAllFiltered(): void
    {
        $this->selected = $this->query()->reorder()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->selectPage = true;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectPage = false;
        $this->bulkCategory = null;
        $this->bulkBrand = null;
    }

    /* ------------------------------------------------------------------ single row */

    public function publish(int $id): void
    {
        $product = Product::findOrFail($id);

        if (! $this->readyToPublish($product)) {
            return;
        }

        $product->update([
            'status'       => Product::STATUS_ACTIVE,
            'published_at' => $product->published_at ?? now(),
        ]);

        $this->dispatch('toast', message: __('admin.published'));
    }

    public function unpublish(int $id): void
    {
        Product::whereKey($id)->update(['status' => Product::STATUS_DRAFT]);
    }

    public function archive(int $id): void
    {
        Product::whereKey($id)->update(['status' => Product::STATUS_ARCHIVED]);
    }

    public function delete(int $id): void
    {
        Product::findOrFail($id)->delete();   // soft delete
        $this->dispatch('toast', message: __('admin.deleted'));
    }

    /* ------------------------------------------------------------------ bulk */

    public function bulkPublish(): void
    {
        $published = 0;
        $skipped = 0;

        foreach (Product::whereKey($this->selected)->get() as $product) {
            if (! $this->readyToPublish($product, silent: true)) {
                $skipped++;

                continue;
            }

            $product->update([
                'status'       => Product::STATUS_ACTIVE,
                'published_at' => $product->published_at ?? now(),
            ]);

            $published++;
        }

        $this->clearSelection();
        $this->dispatch('toast', message: __('admin.bulk_published', ['published' => $published, 'skipped' => $skipped]));
    }

    public function bulkUnpublish(): void
    {
        Product::whereKey($this->selected)->update(['status' => Product::STATUS_DRAFT]);
        $this->clearSelection();
        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function bulkArchive(): void
    {
        Product::whereKey($this->selected)->update(['status' => Product::STATUS_ARCHIVED]);
        $this->clearSelection();
        $this->dispatch('toast', message: __('admin.saved'));
    }

    /**
     * Category and brand are set together, because they are what the import
     * also sets — and both skip products whose taxonomy was locked by hand.
     */
    public function bulkSetCategory(): void
    {
        if (! $this->bulkCategory) {
            return;
        }

        $moved = Product::whereKey($this->selected)
            ->where('taxonomy_lock', false)
            ->update(['category_id' => $this->bulkCategory]);

        $skipped = count($this->selected) - $moved;

        $this->clearSelection();
        $this->dispatch('toast', message: $skipped
            ? __('admin.bulk_moved_locked', ['count' => $moved, 'locked' => $skipped])
            : __('admin.bulk_moved', ['count' => $moved]));
    }

    public function bulkSetBrand(): void
    {
        if (! $this->bulkBrand) {
            return;
        }

        $changed = Product::whereKey($this->selected)
            ->where('taxonomy_lock', false)
            ->update(['brand_id' => $this->bulkBrand]);

        $skipped = count($this->selected) - $changed;

        $this->clearSelection();
        $this->dispatch('toast', message: $skipped
            ? __('admin.bulk_branded_locked', ['count' => $changed, 'locked' => $skipped])
            : __('admin.bulk_branded', ['count' => $changed]));
    }

    /**
     * Locking is what makes a bulk edit last: without it the next import puts
     * the supplier's own category and brand back.
     */
    public function bulkLockTaxonomy(bool $locked = true): void
    {
        Product::whereKey($this->selected)->update(['taxonomy_lock' => $locked]);

        $count = count($this->selected);
        $this->clearSelection();

        $this->dispatch('toast', message: $locked
            ? __('admin.bulk_locked', ['count' => $count])
            : __('admin.bulk_unlocked', ['count' => $count]));
    }

    public function bulkDelete(): void
    {
        $count = count($this->selected);

        Product::whereKey($this->selected)->delete();

        $this->clearSelection();
        $this->dispatch('toast', message: __('admin.bulk_deleted', ['count' => $count]));
    }

    /**
     * A product with no category, name or price would be invisible or broken on
     * the storefront, so publishing it is refused rather than half-done.
     */
    protected function readyToPublish(Product $product, bool $silent = false): bool
    {
        $problems = [];

        if (! $product->category_id) {
            $problems[] = __('admin.no_category');
        }

        if (! $product->name) {
            $problems[] = __('admin.no_name');
        }

        if ((float) $product->price <= 0) {
            $problems[] = __('admin.no_price');
        }

        if (! $problems) {
            return true;
        }

        if (! $silent) {
            $this->dispatch('toast', message: $product->sku.': '.implode(', ', $problems), type: 'error');
        }

        return false;
    }
}
