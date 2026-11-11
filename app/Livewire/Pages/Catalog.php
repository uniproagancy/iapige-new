<?php

namespace App\Livewire\Pages;

use App\Models\Category;
use App\Models\Product;
use App\Support\Catalog as CatalogData;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class Catalog extends Component
{
    public ?int $categoryId = null;

    #[Url(as: 'sub', except: null)]
    public ?int $sub = null;

    #[Url(as: 'sort', except: 'popular')]
    public string $sort = 'popular';

    #[Url(as: 'max', except: null)]
    public ?int $max = null;

    #[Url(as: 'f', except: [])]
    public array $picked = [];

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public int $perPage = 40;

    protected int $step = 40;

    public function mount(?string $slug = null)
    {
        if ($slug === null) {

            if (! $this->picked && $this->max === null && trim($this->q) === '') {
                return;
            }
        } else {
            $category = CatalogData::category($slug);

            if ($category->slug !== $slug) {
                return $this->redirect(route('catalog', $category->slug), navigate: false);
            }

            $this->categoryId = $category->getKey();
        }
    }

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

        /* The filters run down the side of a long page, so picking one half
           way down leaves somebody looking at the empty space where the old
           products were. The page says it has changed; the browser brings the
           products back into view. */
        $this->dispatch('listing-changed');
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

    protected ?Category $resolved = null;

    protected function category(): Category
    {
        return $this->resolved ??= Category::withTranslation()->findOrFail($this->categoryId);
    }

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

        if ($term = trim($this->q)) {
            CatalogData::matching($query, $term);
        }

        foreach ($this->picked as $key => $codes) {
            if ($key === $skip || ! $codes) {
                continue;
            }

            if ($key === 'brand') {
                $query->whereHas('brand', fn (Builder $b) => $b->whereIn('slug', $codes));
            } elseif (isset($this->mainProducts[$key])) {
                $ids = [];
                foreach ($codes as $code) {
                    foreach ($this->mainProducts[$key][$code] ?? [] as $id) {
                        $ids[(int) $id] = true;
                    }
                }
                $query->whereIn('products.id', array_keys($ids));
            } elseif (isset($this->logicalSources[$key])) {
                $sources = $this->selectedSources($key, $codes);
                if ($sources === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas('attributeValues', function (Builder $values) use ($sources) {
                        $values->where(function (Builder $alternatives) use ($sources) {
                            foreach ($sources as $attribute => $answers) {
                                $alternatives->orWhere(fn (Builder $pair) => $pair
                                    ->whereIn('attribute_values.code', $answers)
                                    ->whereHas('attribute', fn (Builder $a) => $a->where('code', $attribute)));
                            }
                        });
                    });
                }
            } else {
                $query->withAttributeValue($key, $this->expanded($key, $codes));
            }
        }

        return $query;
    }

    /*
     | One box in the sidebar can stand for several of the answers underneath
     | it -- a colour for all its shades, a size for both ways of writing it --
     | so what was picked is read back into the answers it was offered for.
     */
    protected array $expands = [];

    /** Virtual choices retain the exact attribute/value pairs, not their cross-product. */
    protected array $logicalSources = [];

    /** Main answers are proved for each product by the same selector as the export. */
    protected array $mainProducts = [];

    protected function remember(array $groups): void
    {
        $this->expands = [];
        $this->logicalSources = [];
        $this->mainProducts = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $this->expands[$group['key']][$item['code']] = $item['codes'] ?? [$item['code']];
                if (isset($item['sources'])) {
                    $this->logicalSources[$group['key']][$item['code']] = $item['sources'];
                }
                if (isset($item['product_ids'])) {
                    $this->mainProducts[$group['key']][$item['code']] = $item['product_ids'];
                }
            }
        }
    }

    protected function selectedSources(string $key, array $codes): array
    {
        $sources = [];
        foreach ($codes as $code) {
            foreach ($this->logicalSources[$key][$code] ?? [] as $attribute => $answers) {
                $sources[$attribute] = array_values(array_unique([...($sources[$attribute] ?? []), ...$answers]));
            }
        }

        return $sources;
    }

    protected function expanded(string $key, array $codes): array
    {
        if (! isset($this->expands[$key])) {
            return $codes;
        }

        $answers = [];

        foreach ($codes as $code) {
            foreach ($this->expands[$key][$code] ?? [$code] as $answer) {
                $answers[] = $answer;
            }
        }

        return array_values(array_unique($answers));
    }

    protected function sorted(Builder $query): Builder
    {
        return match ($this->sort) {
            'price-asc' => $query->orderBy('price'),
            'price-desc' => $query->orderByDesc('price'),
            'new' => $query->orderByDesc('published_at')->orderByDesc('id'),
            default => $query->orderByDesc('sales_count')->orderByDesc('id'),
        };
    }

    protected function facets(array $groups): array
    {
        $counts = [];

        foreach ($groups as $group) {
            $key = $group['key'];
            $matching = $this->query($key)->reorder()->select('products.id')->toBase();

            if (($group['is_main'] ?? false) || ($group['is_main_legacy'] ?? false)) {
                $counts[$key] = $this->mainFacetCounts($group, $matching);

                continue;
            }
            if (isset($group['attribute_codes'])) {
                $counts[$key] = $this->logicalFacetCounts($group, $matching);

                continue;
            }
            $counts[$key] = $key === 'brand'
                ? Product::query()->whereIn('products.id', $matching)
                    ->join('brands', 'brands.id', '=', 'products.brand_id')
                    ->groupBy('brands.slug')
                    ->pluck(DB::raw('count(*)'), 'brands.slug')->all()
                : $this->fold($group, DB::table('attribute_value_product as avp')
                    ->join('attribute_values as av', 'av.id', '=', 'avp.attribute_value_id')
                    ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
                    ->whereIn('avp.product_id', $matching)
                    ->where('a.code', $key)
                    ->groupBy('av.code')
                    ->pluck(DB::raw('count(*)'), 'av.code')->all());
        }

        return $counts;
    }

    protected function logicalFacetCounts(array $group, $matching): array
    {
        $rows = DB::table('attribute_value_product as avp')
            ->join('attribute_values as av', 'av.id', '=', 'avp.attribute_value_id')
            ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->whereIn('avp.product_id', $matching)
            ->whereIn('a.code', $group['attribute_codes'])
            ->select(['avp.product_id', 'a.code as attribute', 'av.code as answer'])
            ->distinct()->get();
        $products = [];
        foreach ($rows as $row) {
            $products[$row->attribute][$row->answer][(int) $row->product_id] = true;
        }
        $counts = [];
        foreach ($group['items'] as $item) {
            $reached = [];
            foreach ($item['sources'] ?? [] as $attribute => $answers) {
                foreach ($answers as $answer) {
                    $reached += $products[$attribute][$answer] ?? [];
                }
            }
            if ($reached !== []) {
                $counts[$item['code']] = count($reached);
            }
        }

        return $counts;
    }

    protected function mainFacetCounts(array $group, $matching): array
    {
        $eligible = [];
        foreach ($group['items'] as $item) {
            foreach ($item['product_ids'] as $id) {
                $eligible[(int) $id] = true;
            }
        }
        $matchingIds = DB::table('products')->whereIn('products.id', $matching)
            ->whereIn('products.id', array_keys($eligible))->pluck('products.id')->all();
        $matchingSet = array_fill_keys($matchingIds, true);
        $counts = [];
        foreach ($group['items'] as $item) {
            $ids = array_fill_keys($item['product_ids'], true);
            $count = count(array_intersect_key($ids, $matchingSet));
            if ($count > 0) {
                $counts[$item['code']] = $count;
            }
        }

        return $counts;
    }

    /* What each box in the sidebar now leads to, which is everything the
       answers underneath it lead to between them. */
    protected function fold(array $group, array $counts): array
    {
        $folded = [];

        foreach ($group['items'] as $item) {
            $reached = 0;

            foreach ($item['codes'] ?? [$item['code']] as $answer) {
                $reached += (int) ($counts[$answer] ?? 0);
            }

            if ($reached > 0) {
                $folded[$item['code']] = $reached;
            }
        }

        return $folded;
    }

    public function render()
    {
        $filtering = $this->categoryId !== null || $this->picked || $this->max !== null || trim($this->q) !== '';

        $data = $filtering ? $this->listingData() : ['categories' => CatalogData::index()];

        return view('livewire.pages.catalog', $data)
            ->extends('layouts.app', [
                'page' => 'catalog',
                'nav' => 'catalog',
                'tab' => 'catalog',
                'pageCss' => 'catalog',
                'seo' => $this->seo($data),
            ])->section('content');
    }

    protected function seo(array $data): array
    {
        $narrowed = $this->picked || $this->max !== null || $this->sub !== null
            || $this->sort !== 'popular' || trim($this->q) !== '';
        $category = $this->categoryId ? $this->category() : null;

        $term = trim($this->q);

        $title = $term !== ''
            ? __('catalog.search_title', ['term' => $term])
            : ($category?->name ?? __('catalog.index_title'));
        $crumbs = $category
            ? array_map(
                fn (Category $c) => ['name' => $c->name, 'url' => route('catalog', $c->slug)],
                $category->ancestorsAndSelf(),
            )
            : [['name' => __('catalog.index_title'), 'url' => route('catalog')]];

        return [

            'title' => ($term === '' && $category?->meta_title) ? $category->meta_title : $title.' — '.config('app.name'),
            'description' => $category?->meta_description
                ?: ($category?->description ?: __('catalog.index_lead')),
            'canonical' => $category ? route('catalog', $category->slug) : route('catalog'),
            'robots' => $narrowed ? 'noindex, follow' : 'index, follow',
            'schema' => [
                Seo::breadcrumbs($crumbs),
                Seo::itemList($data['products'] ?? [], $title),
            ],
        ];
    }

    /*
     | What the shelf offers to filter by.
     |
     | Without a shelf -- a search, a brand's link, a promotion before a
     | department is picked -- there is only brand and price, read off the
     | products found. Working out the filters for the whole shop instead
     | took the "all results" page twenty seconds, and offered colour and
     | weight across televisions and kettles at once; a shelf brings its own
     | filters the moment it is chosen.
     */
    protected function shelf(): array
    {
        return $this->categoryId
            ? CatalogData::listing($this->category(), $this->picked)
            : $this->unshelved();
    }

    protected function unshelved(): array
    {
        $found = $this->query('brand')->reorder();

        $brands = (clone $found)
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->select('brands.slug', 'brands.name')
            ->distinct()
            ->orderBy('brands.name')
            /* Plain rows: as products, "name" and "slug" would answer with
               the product's own translated ones. */
            ->toBase()
            ->get()
            ->filter(fn ($b) => trim((string) $b->slug) !== '' && trim((string) $b->name) !== '')
            ->map(fn ($b) => ['code' => $b->slug, 'label' => $b->name, 'codes' => [$b->slug]])
            ->values()
            ->all();

        /* The slider spans what was found, not what is under the cap already
           set on it -- or it could only ever be pulled lower. */
        $cap = $this->max;
        $this->max = null;

        try {
            $range = $this->query()->reorder()->toBase()->selectRaw('min(price) as low, max(price) as high')->first();
        } finally {
            $this->max = $cap;
        }

        $low = (int) (floor(((float) ($range->low ?? 0)) / 100) * 100);

        return [
            'category' => null,
            'title' => __('catalog.index_title'),
            'breadcrumbs' => [],
            'total' => Product::query()->listable()->count(),
            'subs' => [],
            'sorts' => [
                'popular' => __('catalog.sort.popular'),
                'price-asc' => __('catalog.sort.price_asc'),
                'price-desc' => __('catalog.sort.price_desc'),
                'new' => __('catalog.sort.new'),
            ],
            'groups' => $brands ? [['name' => __('catalog.brand'), 'key' => 'brand', 'open' => true, 'items' => $brands]] : [],
            'filterDiagnostics' => [],
            'priceMin' => $low,
            'priceMax' => max($low + 100, (int) (ceil(((float) ($range->high ?? 0)) / 100) * 100)),
            'perPage' => 20,
        ];
    }

    protected function listingData(): array
    {
        $listing = $this->shelf();

        if ($term = trim($this->q)) {
            $listing['title'] = __('catalog.search_title', ['term' => $term]);
        }

        /* The groups say what each box stands for, and the products are asked
           for after that, because a picked box is only a filter once its
           answers are known. */
        $this->remember($listing['groups']);

        $query = $this->sorted($this->query());
        $total = (clone $query)->reorder()->count();
        $products = CatalogData::cards($query->with(['brand', 'images'])->limit($this->perPage)->get());

        $facets = $this->facets($listing['groups']);
        $listing['groups'] = $this->offered($listing['groups'], $facets);

        return [
            'listing' => $listing,
            'products' => $products,
            'total' => $total,
            'left' => max(0, $total - count($products)),
            'facets' => $facets,
            'active' => $this->activeFilters(),
        ];
    }

    /*
     | What is left to choose from. A shelf offers every answer any of its
     | products ever gave, but once something else has been narrowed down most
     | of them lead nowhere -- and an answer that leads nowhere is not a choice,
     | so it goes rather than sitting there greyed out.
     |
     | A group is kept while there is still something to decide: two answers to
     | pick between, or one already picked and waiting to be let go.
     */
    protected function offered(array $groups, array $facets): array
    {
        foreach ($groups as $at => $group) {
            $key = $group['key'];
            $picked = (array) ($this->picked[$key] ?? []);

            $items = array_values(array_filter(
                $group['items'],
                fn (array $item) => ($facets[$key][$item['code']] ?? 0) > 0
                    || in_array($item['code'], $picked, true),
            ));

            /* What is picked goes to the top, where it can be seen and let go
               of without hunting down a long list for it. The rest keep the
               order they were given in. */
            usort($items, fn (array $a, array $b) => (int) in_array($b['code'], $picked, true)
                <=> (int) in_array($a['code'], $picked, true));

            $groups[$at]['items'] = $items;
        }

        return array_values(array_filter(
            $groups,
            fn (array $group) => count($group['items']) > 1
                || (($group['is_main'] ?? false) && $group['items'] !== [])
                || ($group['key'] === 'brand' && $group['items'] !== [])
                || ($this->picked[$group['key']] ?? []) !== [],
        ));
    }
}
