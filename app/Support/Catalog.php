<?php

namespace App\Support;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class Catalog
{
    public const CARD_RELATIONS = [
        'brand', 'images', 'category', 'attributeValues.attribute', 'livePromotions',
    ];

    protected const MAX_OPTION_LENGTH = 24;

    protected const RESERVED_FILTERS = ['brand', 'brendi', 'manufacturer'];

    public static function productQuery(): Builder
    {
        return Product::query()
            ->listable()
            ->withTranslation()
            ->with(array_merge(self::CARD_RELATIONS, [
                'category.translations',
                'attributeValues.translations',
            ]));
    }

    public static function card(Product $p): array
    {
        $first = $p->images->first();

        $images = $p->images->map->url()->values()->all() ?: [Store::img("p{$p->id}")];

        $filters = ['brand' => $p->brand?->slug];

        foreach ($p->attributeValues as $value) {
            if (! $value->attribute?->is_filterable) {
                continue;
            }

            $code = $value->attribute->code;

            if (in_array($code, self::RESERVED_FILTERS, true)) {
                continue;
            }

            $filters[$code] = isset($filters[$code])
                ? array_merge((array) $filters[$code], [$value->code])
                : $value->code;
        }

        $price = (float) $p->price;
        $promo = $p->promoPrice();

        $sell = $promo ?? $price;
        $was = $promo !== null ? $price : (float) ($p->old_price ?: 0);

        /* Only a higher price is worth striking through; a lower one left on a
           row by a supplier would read as a price rise. */
        $old = $was > $sell ? $was : 0;
        $discount = $old > 0 ? (int) round((1 - $sell / $old) * 100) : null;

        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'brand' => $p->brand?->name ?? '',
            'name' => $p->name,
            'spec' => $p->summary ?? '',
            'price' => $sell,
            'old' => $old,
            'tag' => $old > 0 ? 'sale' : ($p->is_new ? 'new' : null),
            'stock' => __('card.stock_left', ['count' => $p->stock]),
            'stock_raw' => (int) $p->stock,
            'cat' => $p->category?->name ?? '',
            'sub' => $p->category_id,
            'filters' => $filters,
            'order' => $p->sales_count,
            'images' => $images,
            'thumb' => $first?->variant(640) ?? $images[0],
            'thumb_srcset' => $first?->srcset([320, 640]) ?? '',
            'discount' => $discount ? "−{$discount}%" : null,
            'monthly' => $p->monthlyPrice(),
            'url' => route('product', $p->slug),
            'preorder' => (bool) $p->is_preorder,
            'release' => $p->release_date?->translatedFormat('F Y'),
        ];
    }

    public static function cards(Collection $products): array
    {
        return $products->map(fn (Product $p) => self::card($p))->all();
    }

    public static function cartPayload(array $card): array
    {
        return [
            'id' => $card['id'],
            'name' => trim($card['brand'].' '.$card['name']),
            'price' => $card['price'],
        ];
    }

    public static function dealCampaign(): ?Promotion
    {
        return static::$dealMemo[app()->getLocale()] ??= Promotion::live()
            ->where('type', 'deal')
            ->withTranslation()
            ->orderBy('sort_order')
            ->first();
    }

    /* The home page shows one row and no arrows, so a section is only ever
       as long as the widest row: five cards. */
    public const ROW = 5;

    public static function deals(int $limit = self::ROW): array
    {
        if (! $promotion = static::dealCampaign()) {
            return [];
        }

        $products = $promotion->products()
            ->listable()
            ->withTranslation()
            ->with(self::CARD_RELATIONS)

            ->orderByDesc('products.id')
            ->limit($limit)
            ->get();

        return self::cards($products);
    }

    public static function sections(int $perSection = self::ROW): array
    {
        $banners = Store::sectionBanners();
        $stocked = self::stocked();

        return Category::active()->roots()->where('show_on_home', true)
            ->withTranslation()
            ->with(['children' => fn ($q) => $q->active()->withTranslation()])
            ->get()
            ->filter(fn (Category $category) => isset($stocked[$category->id]))
            ->map(function (Category $category) use ($perSection, $banners, $stocked) {
                $ids = $category->descendantAndSelfIds();
                $key = $category->translate(Language::defaultCode(), false)?->slug;

                return [
                    'slug' => $category->slug,
                    'name' => $category->name,
                    'url' => route('catalog', $category->slug),

                    'count' => __('common.products_count', [
                        'count' => $stocked[$category->id],
                    ]),
                    'subs' => $category->children
                        ->filter(fn (Category $c) => isset($stocked[$c->id]))
                        ->map(fn (Category $c) => [
                            'name' => $c->name,
                            'url' => route('catalog', $c->slug),
                        ])->values()->all(),
                    'banner' => $banners[$key]['banner'] ?? null,
                    'duo' => $banners[$key]['duo'] ?? null,

                    'products' => self::cards(self::productQuery()
                        ->whereIn('category_id', $ids)
                        ->orderByDesc('id')
                        ->limit($perSection)
                        ->get()),
                ];
            })
            ->values()
            ->all();
    }

    public static function brands(): array
    {
        return Brand::active()->where('is_featured', true)
            ->whereHas('products', fn (Builder $q) => $q->listable())
            ->withCount(['products' => fn ($q) => $q->listable()])
            ->get()
            ->map(fn (Brand $b) => [
                'name' => $b->name,
                'count' => $b->products_count,
                'logo' => $b->logoUrl(),
                'url' => route('catalog', ['f' => ['brand' => [$b->slug]]]),
            ])
            ->all();
    }

    protected static array $treeMemo = [];

    protected static array $dealMemo = [];

    public static function tree(): array
    {
        return static::$treeMemo[app()->getLocale()] ??= static::buildTree();
    }

    public static function flushTree(): void
    {
        static::$treeMemo = [];
        static::$dealMemo = [];

        self::forgetStocked();
    }

    protected static function buildTree(): array
    {
        $byParent = Category::active()->withTranslation()
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('parent_id');

        $counts = self::categoryCounts();

        $build = function (?int $parentId, int $depth) use (&$build, $byParent, $counts): array {
            $nodes = [];

            foreach ($byParent[$parentId] ?? [] as $category) {
                $children = $build($category->id, $depth + 1);

                $nodes[] = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'url' => route('catalog', $category->slug),

                    'image' => $depth === 1
                        ? ($category->imageUrl() ?? Store::img('iapi-cat-'.$category->id, 0, 76, 76))
                        : null,
                    'total' => ($counts[$category->id] ?? 0) + array_sum(array_column($children, 'total')),
                    'children' => $children,
                ];
            }

            return array_values(array_filter($nodes, fn (array $node) => $node['total'] > 0));
        };

        $decorate = function (array $nodes, int $depth) use (&$decorate): array {
            foreach ($nodes as &$node) {

                $node['count'] = $depth === 1
                    ? __('common.products_count', ['count' => $node['total']])
                    : (string) $node['total'];

                $node['children'] = $decorate($node['children'], $depth + 1);
                $node['subs'] = $node['children'];
            }

            return $nodes;
        };

        return $decorate($build(null, 1), 1);
    }

    public static function menu(int $limit = 6): array
    {
        $stocked = self::stocked();

        return Category::active()->roots()
            ->where('show_in_menu', true)
            ->withTranslation()
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Category $c) => isset($stocked[$c->id]))
            ->take($limit)
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'url' => route('catalog', $c->slug),
            ])
            ->values()
            ->all();
    }

    protected static function categoryCounts()
    {
        return Product::listable()
            ->selectRaw('category_id, count(*) as n')
            ->groupBy('category_id')
            ->pluck('n', 'category_id');
    }

    public const STOCKED_KEY = 'catalog.stocked';

    protected static ?array $stockedMemo = null;

    /* Every page asks which categories have anything in them: the menu, the
       index grid and the "hide empty" checks. It only moves when stock does. */
    public static function stocked(): array
    {
        return self::$stockedMemo ??= Cache::remember(
            self::STOCKED_KEY,
            now()->addMinutes((int) config('shop.stocked_ttl', 10)),
            fn () => self::countStocked(),
        );
    }

    public static function forgetStocked(): void
    {
        self::$stockedMemo = null;

        Cache::forget(self::STOCKED_KEY);
    }

    protected static function countStocked(): array
    {
        $counts = self::categoryCounts();
        $parent = [];
        $total = [];

        foreach (Category::active()->get(['id', 'parent_id']) as $category) {
            $parent[$category->id] = $category->parent_id;
            $total[$category->id] = (int) ($counts[$category->id] ?? 0);
        }

        foreach ($total as $id => $own) {
            if ($own === 0) {
                continue;
            }

            $up = $parent[$id] ?? null;
            $seen = [];

            while ($up !== null && ! isset($seen[$up]) && array_key_exists($up, $total)) {
                $seen[$up] = true;
                $total[$up] += $own;
                $up = $parent[$up] ?? null;
            }
        }

        return array_filter($total);
    }

    public static function index(): array
    {
        $roots = Category::active()->roots()->withTranslation()
            ->with(['children' => fn ($q) => $q->active()->withTranslation()
                ->with(['children' => fn ($q) => $q->active()->withTranslation()])])
            ->get();

        $stocked = self::stocked();

        return $roots
            ->filter(fn (Category $root) => isset($stocked[$root->id]))
            ->map(fn (Category $root) => [
                'id' => $root->id,
                'name' => $root->name,
                'url' => route('catalog', $root->slug),
                'image' => $root->imageUrl() ?? Store::img('iapi-cat-'.$root->id, 0, 320, 240),
                'total' => $stocked[$root->id],
                'subs' => $root->children
                    ->filter(fn (Category $sub) => isset($stocked[$sub->id]))
                    ->map(fn (Category $sub) => [
                        'name' => $sub->name,
                        'url' => route('catalog', $sub->slug),
                        'total' => $stocked[$sub->id],
                    ])
                    ->values()->all(),
            ])->values()->all();
    }

    public static function category(?string $slug): Category
    {
        if ($slug === null) {
            return Category::active()->roots()->withTranslation()->firstOrFail();
        }

        return Category::active()->withTranslation()->whereTranslation('slug', $slug, false)->firstOrFail();
    }

    public static function listing(?Category $category = null): array
    {
        $ids = null;

        if ($category) {
            $category->loadMissing(['children' => fn ($q) => $q->active()->withTranslation()]);
            $ids = $category->descendantAndSelfIds();
        }

        $inListing = fn (Builder $q) => $q
            ->listable()
            ->when($ids, fn (Builder $w) => $w->whereIn('category_id', $ids));

        $total = $inListing(Product::query())->count();

        $range = $inListing(Product::query())
            ->selectRaw('min(price) as low, max(price) as high')
            ->first();

        $brands = Brand::whereHas('products', $inListing)
            ->orderBy('name')
            ->get(['id', 'slug', 'name'])
            ->map(fn (Brand $b) => ['code' => $b->slug, 'label' => $b->name])
            ->values()->all();

        $attributeGroups = Attribute::filterable()->withTranslation()

            ->whereNotIn('code', self::RESERVED_FILTERS)
            ->with(['values' => fn ($q) => $q->withTranslation()
                ->whereHas('products', $inListing)
                ->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Attribute $a) => [
                'name' => $a->name,
                'key' => $a->code,
                'open' => true,
                'items' => $a->values

                    ->filter(fn (AttributeValue $v) => filled($v->label) && mb_strlen($v->label) <= self::MAX_OPTION_LENGTH)
                    ->map(fn (AttributeValue $v) => ['code' => $v->code, 'label' => $v->label])
                    ->values()->all(),
            ])
            ->filter(fn ($g) => count($g['items']) > 1)
            ->values()
            ->all();

        $max = (int) (ceil(((float) ($range->high ?? 0) ?: 1000) / 100) * 100);

        $childCounts = $category && $category->children->isNotEmpty()
            ? self::categoryCounts()
            : collect();

        $groups = count($brands) > 1
            ? array_merge([['name' => __('catalog.brand'), 'key' => 'brand', 'open' => true, 'items' => $brands]], $attributeGroups)
            : $attributeGroups;

        return [
            'category' => $category,
            'title' => $category?->name ?? __('catalog.index_title'),
            'breadcrumbs' => $category
                ? array_map(fn (Category $c) => ['name' => $c->name, 'url' => route('catalog', $c->slug)], $category->ancestorsAndSelf())
                : [],
            'total' => $total,
            'subs' => $category
                ? $category->children->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'url' => route('catalog', $c->slug),
                    'image' => $c->imageUrl() ?? Store::img('iapi-cat-'.$c->id, 0, 320, 240),
                    'total' => collect($c->descendantAndSelfIds())->sum(fn ($id) => $childCounts[$id] ?? 0),
                ])
                    ->filter(fn ($sub) => $sub['total'] > 0)
                    ->values()->all()
                : [],
            'sorts' => [
                'popular' => __('catalog.sort.popular'),
                'price-asc' => __('catalog.sort.price_asc'),
                'price-desc' => __('catalog.sort.price_desc'),
                'new' => __('catalog.sort.new'),
            ],
            'groups' => $groups,
            'priceMin' => (int) (floor(((float) ($range->low ?? 0)) / 100) * 100),
            'priceMax' => $max,
            'perPage' => 20,
        ];
    }

    public static function campaigns(): array
    {
        return Promotion::live()
            ->withTranslation()
            ->withCount('products')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Promotion $p) => [
                'slug' => $p->urlKey(),
                'title' => $p->title ?: $p->code,
                'subtitle' => $p->subtitle,
                'badge' => $p->badge,
                'image' => $p->image,
                'count' => (int) $p->products_count,
                'ends_at' => $p->ends_at?->toIso8601String(),
                'url' => route('promotion', $p->urlKey()),
            ])
            ->filter(fn (array $c) => $c['count'] > 0)
            ->values()
            ->all();
    }

    public static function campaign(string $slug): Promotion
    {
        return Promotion::live()
            ->withTranslation()
            ->where(fn ($q) => $q
                ->whereTranslation('slug', $slug, false)

                ->orWhere('code', $slug))
            ->firstOrFail();
    }

    public static function campaignPage(Promotion $promotion): array
    {
        $products = $promotion->products()
            ->active()
            ->withTranslation()
            ->with(self::CARD_RELATIONS)
            ->orderBy('promotion_product.sort_order')
            ->orderByDesc('products.id')
            ->get();

        return [
            'slug' => $promotion->urlKey(),
            'title' => $promotion->title ?: $promotion->code,
            'subtitle' => $promotion->subtitle,
            'badge' => $promotion->badge,
            'ends_at' => $promotion->ends_at?->toIso8601String(),
            'products' => self::cards($products),
        ];
    }

    public static function product(string $slug): Product
    {
        return Product::active()->withTranslation()->whereTranslation('slug', $slug, false)
            ->with(['specs' => fn ($q) => $q->withTranslation()->with(['attribute' => fn ($a) => $a->withTranslation()])])
            ->firstOrFail();
    }

    public static function productPage(Product $product): array
    {
        $product->loadMissing(array_merge(self::CARD_RELATIONS, [
            'category.translations',
            'attributeValues.translations',
            'specs.translations',
            'specs.attribute.translations',
        ]));

        $card = self::card($product);
        $gallery = $product->images->map(fn (ProductImage $i) => $i->variant(1280))->values()->all() ?: $card['images'];
        $thumbs = $product->images->map(fn (ProductImage $i) => $i->variant(320))->values()->all() ?: $card['images'];

        $variants = self::variantSwitchers($product);

        [$colors, $storage] = self::splitVariants($variants);

        $usable = fn ($row) => filled($row[0]) && filled($row[1]) && ! in_array(trim($row[1]), ['-', '—', 'N/A'], true);

        $specs = $product->specs
            ->map(fn ($s) => [$s->attribute?->name, $s->value])
            ->filter($usable)
            ->values();

        $keySpecs = $product->specs->where('is_key', true)
            ->map(fn ($s) => [$s->attribute?->name, $s->value])
            ->filter($usable)
            ->values()
            ->prepend([__('product.brand'), $card['brand']])
            ->all();

        $extras = Store::productExtras();

        $bundle = self::productQuery()->whereIn('sku', $extras['bundle_skus'])->get()
            ->sortBy(fn (Product $p) => array_search($p->sku, $extras['bundle_skus']))
            ->map(fn (Product $p) => self::card($p))
            ->prepend($card)
            ->values()
            ->map(fn ($c, $i) => $c + ['fixed' => $i === 0])
            ->all();

        $related = self::related($product);

        return [
            'product' => $card + [
                'code' => $product->sku,
                'gallery' => $gallery,
                'thumbs' => $thumbs,
                'description' => $product->description,
                'breadcrumbs' => $product->category ? array_map(
                    fn (Category $c) => ['name' => $c->name, 'url' => route('catalog', $c->slug)],
                    $product->category->ancestorsAndSelf(),
                ) : [],
            ],
            'model' => $product,
            'colors' => $colors,
            'configs' => $storage,
            'bundleIds' => array_column($bundle, 'id'),
            'keySpecs' => $keySpecs,
            'specs' => $specs->all(),
            'highlights' => $extras['highlights'],
            'rating' => ['score' => (float) $product->rating, 'count' => $product->reviews_count, 'bars' => $extras['rating_bars']],
            'reviews' => $extras['reviews'],
            'services' => $extras['services'],
            'bundle' => $bundle,
            'related' => $related,

            'relatedUrl' => $product->category?->slug
                ? route('catalog', $product->category->slug)
                : route('catalog'),
        ];
    }

    public static function related(Product $product, int $limit = 5): array
    {
        $found = $product->category_id
            ? self::productQuery()
                ->where('category_id', $product->category_id)
                ->whereKeyNot($product->id)
                ->orderByDesc('sales_count')
                ->limit($limit)
                ->get()

            : new EloquentCollection;

        $missing = $limit - $found->count();

        if ($missing > 0 && $product->category?->parent) {
            $siblings = self::productQuery()
                ->whereIn('category_id', $product->category->parent->descendantAndSelfIds())
                ->whereKeyNot($product->id)
                ->whereNotIn('id', $found->pluck('id'))
                ->orderByDesc('sales_count')
                ->limit($missing)
                ->get();

            $found = $found->concat($siblings);
        }

        return self::cards($found);
    }

    protected static function siblingsOf(Product $product): Collection
    {
        $query = Product::active()->withTranslation()
            ->with(['attributeValues' => fn ($q) => $q->withTranslation()
                ->whereHas('attribute', fn ($a) => $a->where('is_variant', true))
                ->with('attribute')])
            ->orderBy('price');

        if ($product->variant_group) {
            return $query->where('variant_group', $product->variant_group)->get();
        }

        return $query->where('sku', $product->sku)->get();
    }

    protected static function splitVariants(array $variants): array
    {
        $colors = [];
        $other = [];

        foreach ($variants as $code => $options) {
            $isColour = collect($options)->contains(fn (array $o) => filled($o['hex'] ?? null));

            if ($isColour && ! $colors) {
                $colors = $options;

                continue;
            }

            $other[$code] = $options;
        }

        ksort($other);

        return [$colors, $other ? reset($other) : []];
    }

    public static function variantSwitchers(Product $product): array
    {
        $siblings = self::siblingsOf($product);

        if ($siblings->count() < 2) {
            return [];
        }

        $here = self::variantValues($product);
        $switchers = [];

        foreach ($siblings as $sibling) {
            $isCurrent = $sibling->is($product);
            $theirs = self::variantValues($sibling);

            foreach ($sibling->attributeValues as $value) {
                if (! $value->attribute) {
                    continue;
                }

                $code = $value->attribute->code;
                $kept = self::keptBesides($here, $theirs, $code);
                $existing = $switchers[$code][$value->code] ?? null;

                /* Of every sibling offering this option, the one to link to is
                   the one that leaves the rest of the choice alone — picking a
                   colour should not quietly change the memory as well. */
                if ($existing && ($existing['current'] || (! $isCurrent && $existing['kept'] >= $kept))) {
                    continue;
                }

                $switchers[$code][$value->code] = [
                    'code' => $value->code,
                    'label' => $value->label,
                    'hex' => $value->color_hex,
                    'url' => $isCurrent ? null : route('product', $sibling->slug),
                    'current' => $isCurrent,
                    'kept' => $kept,
                ];
            }
        }

        return array_map(
            fn (array $options) => array_values(array_map(
                fn (array $option) => Arr::except($option, 'kept'),
                $options,
            )),
            $switchers,
        );
    }

    /* attribute code => chosen value code, for the variant attributes only */
    protected static function variantValues(Product $product): array
    {
        $values = [];

        foreach ($product->attributeValues as $value) {
            if ($value->attribute) {
                $values[$value->attribute->code] = $value->code;
            }
        }

        return $values;
    }

    /* How much of the current choice a sibling keeps, the switched attribute
       itself left out of the count. */
    protected static function keptBesides(array $here, array $theirs, string $switching): int
    {
        $kept = 0;

        foreach ($here as $code => $chosen) {
            if ($code !== $switching && ($theirs[$code] ?? null) === $chosen) {
                $kept++;
            }
        }

        return $kept;
    }

    public static function matching(Builder $query, string $term): Builder
    {
        return $query->where(function ($q) use ($term) {
            $q->whereHas('translations', fn ($t) => $t
                ->where('locale', app()->getLocale())
                ->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('summary', 'like', "%{$term}%")))
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$term}%"));
        });
    }

    public static function search(string $term, int $limit = 5): array
    {
        $products = self::matching(self::productQuery(), $term)
            ->orderByDesc('sales_count')
            ->limit($limit)
            ->get();

        return self::cards($products);
    }
}
