<?php

namespace App\Livewire\Pages;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion as PromotionModel;
use App\Support\Catalog as CatalogData;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/*
 | A promotion is a shelf of its own: the same filters, sorting and paging as
 | the catalog, over what the promotion has on offer. A campaign of fifteen
 | hundred items across every department was one long wall of cards with no
 | way into it, so it is cut first by department -- each one with its own
 | filters once chosen -- and then by brand, price and the rest.
 */
class Promotion extends Catalog
{
    #[Url(as: 'cat', except: null)]
    public ?int $categoryId = null;

    public string $slug = '';

    public int $promotionId = 0;

    protected ?PromotionModel $campaign = null;

    public function mount(?string $slug = null)
    {
        $this->slug = (string) $slug;
        $this->promotionId = $this->campaign()->id;
    }

    /* A department is a fresh start: what was picked in one says nothing
       about another. */
    public function chooseCategory(?int $id): void
    {
        $this->categoryId = $this->categoryId === $id ? null : $id;
        $this->reset(['sub', 'picked', 'max']);
        $this->resetPaging();
        $this->dispatch('listing-changed');
    }

    protected function campaign(): PromotionModel
    {
        return $this->campaign ??= CatalogData::campaign($this->slug);
    }

    protected function query(?string $skip = null): Builder
    {
        return parent::query($skip)->whereIn('products.id', $this->onOffer());
    }

    protected function onOffer()
    {
        return DB::table('promotion_product')
            ->where('promotion_id', $this->promotionId)
            ->select('product_id');
    }

    /*
     | Before a department is chosen, brand and price are all there is to
     | filter by. The catalog's filters for the whole shop -- colour, weight,
     | warranty across every kind of product -- cost six seconds to work out
     | and say little about a television and a kettle at once; each
     | department brings its own the moment it is chosen.
     */
    protected function shelf(): array
    {
        if ($this->categoryId) {
            return parent::shelf();
        }

        $brands = Product::query()->listable()
            ->whereIn('products.id', $this->onOffer())
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

        return [
            'category' => null,
            'title' => '',
            'breadcrumbs' => [],
            'total' => 0,
            'subs' => [],
            'sorts' => [
                'popular' => __('catalog.sort.popular'),
                'price-asc' => __('catalog.sort.price_asc'),
                'price-desc' => __('catalog.sort.price_desc'),
                'new' => __('catalog.sort.new'),
            ],
            'groups' => $brands ? [['name' => __('catalog.brand'), 'key' => 'brand', 'open' => true, 'items' => $brands]] : [],
            'filterDiagnostics' => [],
            'priceMin' => 0,
            'priceMax' => 0,
            'perPage' => 20,
        ];
    }

    /*
     | The departments the promotion reaches, each with how much of it is on
     | offer there. Products sit on the deepest shelf, so each is counted
     | under the department at the top of its line.
     */
    protected function departments(): array
    {
        $counts = Product::query()->listable()
            ->whereIn('products.id', $this->onOffer())
            ->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as n')
            ->groupBy('category_id')
            ->pluck('n', 'category_id');

        $parents = Category::pluck('parent_id', 'id');
        $totals = [];

        foreach ($counts as $id => $n) {
            $top = (int) $id;

            for ($guard = 0; ($parents[$top] ?? null) && $guard < 12; $guard++) {
                $top = (int) $parents[$top];
            }

            $totals[$top] = ($totals[$top] ?? 0) + (int) $n;
        }

        return Category::withTranslation()->active()->whereIn('id', array_keys($totals))->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'total' => $totals[$c->id],
                'image' => $c->imageUrl(),
                'icon' => $c->image ? null : ($c->icon ?: CatalogData::DEFAULT_ICON),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    public function render()
    {
        $campaign = $this->campaign();
        $departments = $this->departments();

        /* A department taken from an old link that the promotion no longer
           reaches is let go rather than showing an empty shelf. */
        if ($this->categoryId && ! collect($departments)->contains('id', $this->categoryId)) {
            $this->categoryId = null;
            $this->sub = null;
        }

        $data = $this->listingData();
        $data['listing'] = $this->narrowed($data['listing'], $campaign);

        return view('livewire.pages.promotion', $data + [
            'campaign' => [
                'slug' => $campaign->urlKey(),
                'title' => $campaign->title ?: $campaign->code,
                'subtitle' => $campaign->subtitle,
                'ends_at' => $campaign->ends_at?->toIso8601String(),
            ],
            'departments' => $departments,
            'onOffer' => array_sum(array_column($departments, 'total')),
        ])->extends('layouts.app', [
            'page' => 'promotion',
            'nav' => 'catalog',
            'tab' => 'catalog',
            'pageCss' => 'catalog',
            'seo' => $this->promotionSeo($campaign, $data['products']),
        ])->section('content');
    }

    /*
     | The shelf as the catalog describes it, told again about the promotion:
     | its sub-shelves only where something is on offer, counted by what is,
     | and a price range that spans the promotion rather than the whole shop.
     */
    protected function narrowed(array $listing, PromotionModel $campaign): array
    {
        $department = $this->categoryId ? $this->category() : null;

        $listing['title'] = $department?->name ?? ($campaign->title ?: $campaign->code);
        $listing['subs'] = $department ? $this->offeredSubs($listing['subs']) : [];

        $scope = Product::query()->listable()->whereIn('products.id', $this->onOffer())
            ->when($department, fn (Builder $q) => $q->whereIn('category_id', $department->descendantAndSelfIds()));

        $listing['total'] = (clone $scope)->count();

        $range = $scope->selectRaw('min(price) as low, max(price) as high')->first();
        $listing['priceMin'] = (int) (floor(((float) ($range->low ?? 0)) / 100) * 100);
        $listing['priceMax'] = max($listing['priceMin'] + 100, (int) (ceil(((float) ($range->high ?? 0)) / 100) * 100));

        return $listing;
    }

    protected function offeredSubs(array $subs): array
    {
        $offered = [];

        foreach ($subs as $sub) {
            $ids = Category::find($sub['id'])?->descendantAndSelfIds() ?? [$sub['id']];
            $total = Product::query()->listable()
                ->whereIn('products.id', $this->onOffer())
                ->whereIn('category_id', $ids)
                ->count();

            if ($total > 0) {
                $offered[] = ['total' => $total] + $sub;
            }
        }

        return $offered;
    }

    protected function promotionSeo(PromotionModel $campaign, array $products): array
    {
        $title = $campaign->title ?: $campaign->code;

        return [
            'title' => $title.' — '.config('app.name'),
            'description' => $campaign->subtitle ?: __('promo.meta', ['name' => $title]),
            'canonical' => route('promotion', $campaign->urlKey()),

            'robots' => 'noindex, follow',
            'schema' => [
                Seo::breadcrumbs([
                    ['name' => __('promo.all'), 'url' => route('promotions')],
                    ['name' => $title, 'url' => route('promotion', $campaign->urlKey())],
                ]),
                Seo::itemList($products, $title),
            ],
        ];
    }
}
