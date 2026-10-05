<?php

namespace App\Livewire\Pages;

use App\Models\Category;
use App\Models\Product;
use App\Support\Catalog as CatalogData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /catalog            → every category as a grid
 * /catalog/{slug}     → products of that category, with filters in the URL
 */
class Catalog extends Component
{
    public ?int $categoryId = null;

    #[Url(as: 'sub', except: null)]
    public ?int $sub = null;

    #[Url(as: 'sort', except: 'popular')]
    public string $sort = 'popular';

    #[Url(as: 'max', except: null)]
    public ?int $max = null;

    /** ['brand' => ['norda'], 'ram' => ['16gb']] */
    #[Url(as: 'f', except: [])]
    public array $picked = [];

    public int $perPage = 8;

    protected int $step = 8;

     public function mount(?string $slug = null)
    {
        if ($slug === null) {
            // /catalog?f[brand][]=apple → a filtered listing across every category
            if (! $this->picked && $this->max === null) {
                return; // plain landing page
            }
        } else {
            $category = CatalogData::category($slug);

            if ($category->slug !== $slug) {
                return $this->redirect(route('catalog', $category->slug), navigate: false);
            }

            $this->categoryId = $category->getKey();
        }
    }

    /* ------------------------------------------------------------------ actions */

    public function toggle(string $key, string $code): void
    {
        $values = $this->picked[$key] ?? [];

        $this->picked[$key] = in_array($code, $values, true)
            ? array_values(array_diff($values, [$code]))
            : [...$values, $code];

        if (! $this->picked[$key]) {
            unset($this->picked[$key]);
        }

        $this->resetPaging();
    }

    public function chooseSub(?int $id): void
    {
        $this->sub = $this->sub === $id ? null : $id;
        $this->resetPaging();
    }

    public function clear(): void
    {
        $this->reset(['sub', 'max', 'picked']);
        $this->resetPaging();
    }

    public function loadMore(): void
    {
        $this->perPage += $this->step;
    }

    public function updatedSort(): void
    {
        $this->resetPaging();
    }

    public function updatedMax(): void
    {
        $this->resetPaging();
    }

    protected function resetPaging(): void
    {
        $this->perPage = $this->step;
    }

    public function activeFilters(): int
    {
        return collect($this->picked)->flatten()->count()
            + ($this->sub ? 1 : 0)
            + ($this->max !== null ? 1 : 0);
    }

    /* ------------------------------------------------------------------ queries */

    protected function category(): Category
    {
        return Category::withTranslation()->findOrFail($this->categoryId);
    }

    /** The category's products with every filter applied except $skip's group. */
    protected function query(?string $skip = null): Builder
    {
        $query = CatalogData::productQuery();

        if ($this->sub) {
            $query->where('category_id', $this->sub);
        } elseif ($this->categoryId) {
            $query->whereIn('category_id', $this->category()->descendantAndSelfIds());
        }

        if ($this->max !== null) {
            $query->where('price', '<=', $this->max);
        }

        foreach ($this->picked as $key => $codes) {
            if ($key === $skip || ! $codes) {
                continue;
            }

            $key === 'brand'
                ? $query->whereHas('brand', fn (Builder $b) => $b->whereIn('slug', $codes))
                : $query->withAttributeValue($key, $codes);
        }

        return $query;
    }

    protected function sorted(Builder $query): Builder
    {
        return match ($this->sort) {
            'price-asc'  => $query->orderBy('price'),
            'price-desc' => $query->orderByDesc('price'),
            'new'        => $query->orderByDesc('published_at')->orderByDesc('id'),
            default      => $query->orderByDesc('sales_count')->orderByDesc('id'),
        };
    }

    /** How many products each option would leave — its own group is ignored. */
    protected function facets(array $groups): array
    {
        $counts = [];

        foreach ($groups as $group) {
            $key = $group['key'];
            $ids = $this->query($key)->reorder()->pluck('products.id');

            $counts[$key] = $key === 'brand'
                ? Product::query()->whereIn('products.id', $ids)
                    ->join('brands', 'brands.id', '=', 'products.brand_id')
                    ->groupBy('brands.slug')
                    ->pluck(DB::raw('count(*)'), 'brands.slug')->all()
                : DB::table('attribute_value_product as avp')
                    ->join('attribute_values as av', 'av.id', '=', 'avp.attribute_value_id')
                    ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
                    ->whereIn('avp.product_id', $ids)
                    ->where('a.code', $key)
                    ->groupBy('av.code')
                    ->pluck(DB::raw('count(*)'), 'av.code')->all();
        }

        return $counts;
    }

    public function render()
    {
        $filtering = $this->categoryId !== null || $this->picked || $this->max !== null;

        $data = $filtering ? $this->listingData() : ['categories' => CatalogData::index()];

        return view('livewire.pages.catalog', $data)
            ->extends('layouts.app', [
                'page'    => 'catalog',
                'nav'     => 'catalog',
                'tab'     => 'catalog',
                'pageCss' => 'catalog',
            ])->section('content');
    }

    protected function listingData(): array
    {
		$listing = CatalogData::listing($this->categoryId ? $this->category() : null);

        $query = $this->sorted($this->query());
        $total = (clone $query)->reorder()->count();
        $products = CatalogData::cards($query->with(['brand', 'images'])->limit($this->perPage)->get());

        return [
            'listing'  => $listing,
            'products' => $products,
            'total'    => $total,
            'left'     => max(0, $total - count($products)),
            'facets'   => $this->facets($listing['groups']),
            'active'   => $this->activeFilters(),
        ];
    }
}