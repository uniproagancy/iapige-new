<?php

namespace App\Support;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads the catalogue from the database and returns the array shapes the
 * Blade components already use (<x-product-card :product="…"> etc.).
 * Everything here respects the current locale through HasTranslations.
 */
class Catalog
{
    /** Relations every product card needs. */
    /**
     * Relations every product card needs.
     *
     * The running campaigns are among them: a card has to know whether the
     * product it is drawing is on offer, and asking per product turns one
     * listing into a query per row.
     */
    public const CARD_RELATIONS = [
        'brand', 'images', 'category', 'attributeValues.attribute', 'livePromotions',
    ];

    /** A filter option longer than this is a free-text spec, not a choice. */
    protected const MAX_OPTION_LENGTH = 24;

    /**
     * Attributes the sidebar builds itself and must not draw twice.
     * The brand group comes from the products' own brand, not from a spec.
     */
    protected const RESERVED_FILTERS = ['brand', 'brendi', 'manufacturer'];

    /* ================================================================== product card */

    public static function productQuery(): Builder
    {
        return Product::query()
            ->active()
            ->withTranslation()
            ->with(array_merge(self::CARD_RELATIONS, [
                'category.translations',
                'attributeValues.translations',
            ]));
    }

    /** One product in the shape of <x-product-card>. */
    public static function card(Product $p): array
    {
        $images = $p->images->map->url()->values()->all() ?: [Store::img("p{$p->id}")];

        // a product may carry several values of one attribute, so every code is kept
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

        /*
         | A campaign price belongs on every card, not only on the deals rail.
         |
         | The rail applied it and nothing else did, so a product the shop had
         | marked down itself was advertised at one price on the front page and
         | listed at another in its category, in search and among related
         | products — the same figure the cart used to disagree with. One place
         | works it out now, and every listing draws from here.
         |
         | A supplier's own discount still shows when there is no campaign: the
         | two never both apply, so there is nothing to conflict.
         */
        $price = (float) $p->price;
        $promo = $p->promoPrice();

        $sell = $promo ?? $price;
        $old = $promo !== null ? $price : ($p->old_price ? (float) $p->old_price : 0);
        $discount = $old > $sell ? (int) round((1 - $sell / $old) * 100) : null;

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
            'thumb' => $images[0],
            'discount' => $discount ? "−{$discount}%" : null,
            'monthly' => $p->monthlyPrice(),
            'url' => route('product', $p->slug),
            'preorder' => (bool) $p->is_preorder,
            'release' => $p->release_date?->translatedFormat('F Y'),
        ];
    }

    /** @param  Collection<int, Product>  $products */
    public static function cards(Collection $products): array
    {
        return $products->map(fn (Product $p) => self::card($p))->all();
    }

    /** Payload the add-to-cart button sends (the server re-reads price itself). */
    public static function cartPayload(array $card): array
    {
        return [
            'id' => $card['id'],
            'name' => trim($card['brand'].' '.$card['name']),
            'price' => $card['price'],
        ];
    }

    /* ================================================================== home */

    /**
     * The running "deal" campaign. Its price wins over the catalogue price and
     * the regular price becomes the struck-through one — nothing is written to
     * products, so the campaign simply expires.
     */
    /**
     * The campaign the deals rail is showing, if any is running.
     *
     * Separate from deals() because the rail needs its title as well as its
     * products: the heading above the rail was a fixed phrase from the
     * language file, so a campaign called "Black Friday" in the admin still
     * announced itself as the offer of the week.
     */
    public static function dealCampaign(): ?Promotion
    {
        return static::$dealMemo[app()->getLocale()] ??= Promotion::live()
            ->where('type', 'deal')
            ->withTranslation()
            ->orderBy('sort_order')
            ->first();
    }

    public static function deals(int $limit = 7): array
    {
        if (! $promotion = static::dealCampaign()) {
            return [];
        }

        $products = $promotion->products()
            ->active()
            ->withTranslation()
            ->with(self::CARD_RELATIONS)
            // no order at all meant the database chose, and it chose the oldest
            ->orderByDesc('products.id')
            ->limit($limit)
            ->get();

        /*
         * No special pricing here any more. card() applies the campaign, which
         * is what stopped the rail and the rest of the shop disagreeing — this
         * method used to be the only place that knew about offers at all.
         */
        return self::cards($products);
    }

    /** Root categories flagged show_on_home, each with its best sellers. */
    /**
     * The home page's category rails.
     *
     * Twenty a rail, not five. The rail fits exactly five cards across, so a
     * section holding five had nothing to scroll — and the arrows beside its
     * heading, which disable themselves when there is nowhere to go, were
     * therefore dead on every section of the page.
     */
    public static function sections(int $perSection = 20): array
    {
        $banners = Store::sectionBanners();
        $counts = self::categoryCounts();

        return Category::active()->roots()->where('show_on_home', true)
            ->withTranslation()
            ->with(['children' => fn ($q) => $q->active()->withTranslation()])
            ->get()
            ->map(function (Category $category) use ($perSection, $banners, $counts) {
                $ids = $category->descendantAndSelfIds();
                $key = $category->translate(Language::defaultCode(), false)?->slug;

                return [
                    'slug' => $category->slug,
                    'name' => $category->name,
                    'url' => route('catalog', $category->slug),
                    // counted from the same map as everywhere else, not a query per section
                    'count' => __('common.products_count', [
                        'count' => collect($ids)->sum(fn ($id) => $counts[$id] ?? 0),
                    ]),
                    'subs' => $category->children->map(fn (Category $c) => [
                        'name' => $c->name,
                        'url' => route('catalog', $c->slug),
                    ])->all(),
                    'banner' => $banners[$key]['banner'] ?? null,
                    'duo' => $banners[$key]['duo'] ?? null,
                    /*
                     * Newest first. Ordering by sales_count put the oldest
                     * products at the top of every section, because nothing
                     * has sold yet and a column of equal values leaves the
                     * order to the database — which hands back insertion
                     * order. A shop still filling its catalogue wants its
                     * newest arrivals seen.
                     */
                    'products' => self::cards(self::productQuery()
                        ->whereIn('category_id', $ids)
                        ->orderByDesc('id')
                        ->limit($perSection)
                        ->get()),
                ];
            })
            ->all();
    }

    /** Featured brands for the home page rail; each links to a filtered listing. */
    public static function brands(): array
    {
        return Brand::active()->where('is_featured', true)
            ->withCount(['products' => fn ($q) => $q->active()])
            ->get()
            ->map(fn (Brand $b) => [
                'name' => $b->name,
                'count' => $b->products_count,
                'logo' => $b->logoUrl(),
                'url' => route('catalog', ['f' => ['brand' => [$b->slug]]]),
            ])
            ->all();
    }

    /* ================================================================== category tree (drawer + sidebar) */

    /** Built once per request and language — the drawer, the footer and the sidebar all use it. */
    protected static array $treeMemo = [];

    /** @var array<string, ?Promotion> */
    protected static array $dealMemo = [];

    public static function tree(): array
    {
        return static::$treeMemo[app()->getLocale()] ??= static::buildTree();
    }

    /**
     * Forgets the built tree.
     *
     * Static state outlives a request in a test run and under a persistent
     * worker, where "once per request" quietly becomes "once per process" and
     * a category added afterwards never appears.
     */
    public static function flushTree(): void
    {
        static::$treeMemo = [];
        static::$dealMemo = [];
    }

    /**
     * The whole tree, however deep it goes.
     *
     * This used to eager-load exactly two levels of children and shape them as
     * root → subs → leaves, with a leaf being a flat [name, count, url] triple.
     * A shop built three deep fitted that exactly — and the moment a fourth
     * level was added it could not be rendered at all, because a leaf had
     * nowhere to put children. Those categories were reachable only by typing
     * their address.
     *
     * Every node now carries `children`, so depth is whatever the data says.
     * `subs` stays as an alias because the sidebar and footer read it.
     *
     * One query for the lot. The old count closure walked `$c->children` past
     * the two loaded levels, which lazy-loaded a query per node — invisible on
     * a small tree and a few hundred queries on a large one.
     */
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
                    // only the first level is shown with a picture
                    'image' => $depth === 1
                        ? ($category->imageUrl() ?? Store::img('iapi-cat-'.$category->id, 0, 76, 76))
                        : null,
                    'total' => ($counts[$category->id] ?? 0) + array_sum(array_column($children, 'total')),
                    'children' => $children,
                ];
            }

            return $nodes;
        };

        $decorate = function (array $nodes, int $depth) use (&$decorate): array {
            foreach ($nodes as &$node) {
                // the first level prints the word, the rest just the number
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

    /** Root categories flagged for the main navigation. */
    public static function menu(int $limit = 6): array
    {
        return Category::active()->roots()
            ->where('show_in_menu', true)
            ->withTranslation()
            ->orderBy('sort_order')
            ->limit($limit)
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'url' => route('catalog', $c->slug),
            ])
            ->all();
    }

    /** category_id => how many active products sit directly in it. */
    protected static function categoryCounts()
    {
        return Product::active()
            ->selectRaw('category_id, count(*) as n')
            ->groupBy('category_id')
            ->pluck('n', 'category_id');
    }

    /* ================================================================== catalog page */

    /**
     * The catalog landing page: every root category with its sub-categories
     * and how many products sit underneath (the whole branch, not just the
     * products attached directly to that category).
     */
    public static function index(): array
    {
        $roots = Category::active()->roots()->withTranslation()
            ->with(['children' => fn ($q) => $q->active()->withTranslation()
                ->with(['children' => fn ($q) => $q->active()->withTranslation()])])
            ->get();

        $counts = self::categoryCounts();

        $count = function (Category $c) use (&$count, $counts): int {
            return ($counts[$c->id] ?? 0) + $c->children->sum(fn (Category $child) => $count($child));
        };

        return $roots->map(fn (Category $root) => [
            'id' => $root->id,
            'name' => $root->name,
            'url' => route('catalog', $root->slug),
            'image' => $root->imageUrl() ?? Store::img('iapi-cat-'.$root->id, 0, 320, 240),
            'total' => $count($root),
            'subs' => $root->children
                ->map(fn (Category $sub) => [
                    'name' => $sub->name,
                    'url' => route('catalog', $sub->slug),
                    'total' => $count($sub),
                ])
                ->filter(fn ($sub) => $sub['total'] > 0)
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

    /**
     * Filters, facets and price range for a listing.
     * $category === null means the whole catalogue (a brand filter from the home page).
     */
    public static function listing(?Category $category = null): array
    {
        $ids = null;

        if ($category) {
            $category->loadMissing(['children' => fn ($q) => $q->active()->withTranslation()]);
            $ids = $category->descendantAndSelfIds();
        }

        $products = self::productQuery()
            ->when($ids, fn ($q) => $q->whereIn('category_id', $ids))
            ->orderByDesc('sales_count')
            ->get();

        $cards = self::cards($products);
        $productIds = $products->pluck('id');

        $brands = $products->pluck('brand')->filter()->unique('id')->sortBy('name')
            ->map(fn (Brand $b) => ['code' => $b->slug, 'label' => $b->name])->values()->all();

        // options come from the pivot, not from the cards: a product may carry
        // several values of one attribute and the card array cannot show that
        $attributeGroups = Attribute::filterable()->withTranslation()
            // the brand group is built above from the products themselves; an
            // attribute of the same name would draw a second, conflicting one
            ->whereNotIn('code', self::RESERVED_FILTERS)
            ->with(['values' => fn ($q) => $q->withTranslation()
                ->whereHas('products', fn ($p) => $p->whereIn('products.id', $productIds))
                ->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Attribute $a) => [
                'name' => $a->name,
                'key' => $a->code,
                'open' => true,
                'items' => $a->values
                    // a nameless or free-text value is a spec, never a filter option
                    ->filter(fn (AttributeValue $v) => filled($v->label) && mb_strlen($v->label) <= self::MAX_OPTION_LENGTH)
                    ->map(fn (AttributeValue $v) => ['code' => $v->code, 'label' => $v->label])
                    ->values()->all(),
            ])
            ->filter(fn ($g) => count($g['items']) > 1)   // a single option filters nothing
            ->values()
            ->all();

        $prices = $products->pluck('price')->map(fn ($p) => (float) $p);
        $max = (int) (ceil(($prices->max() ?: 1000) / 100) * 100);

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
            'total' => count($cards),
            'subs' => $category
                ? $category->children->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'url' => route('catalog', $c->slug),
                    'image' => $c->imageUrl() ?? Store::img('iapi-cat-'.$c->id, 0, 320, 240),
                    'total' => collect($c->descendantAndSelfIds())->sum(fn ($id) => $childCounts[$id] ?? 0),
                ])
                    ->filter(fn ($sub) => $sub['total'] > 0)   // an empty sub-category helps nobody
                    ->values()->all()
                : [],
            'sorts' => [
                'popular' => __('catalog.sort.popular'),
                'price-asc' => __('catalog.sort.price_asc'),
                'price-desc' => __('catalog.sort.price_desc'),
                'new' => __('catalog.sort.new'),
            ],
            'groups' => $groups,
            'priceMin' => (int) (floor(($prices->min() ?: 0) / 100) * 100),
            'priceMax' => $max,
            'perPage' => 8,
            'products' => $cards,
        ];
    }

    /* ================================================================== product page */

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
        $gallery = $product->images->map->url()->values()->all() ?: $card['images'];

        // the switchers cover this product and every sibling that shares its group
        $variants = self::variantSwitchers($product);

        $colors = $variants['color'] ?? [];
        $storage = $variants['storage'] ?? [];

        // a spec with no value would render as an empty row
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

        $related = self::cards(self::productQuery()
            ->whereIn('category_id', $product->category?->parent
                ? $product->category->parent->descendantAndSelfIds()
                : array_filter([$product->category_id]))
            ->whereKeyNot($product->id)
            ->orderByDesc('sales_count')
            ->limit(5)
            ->get());

        return [
            'product' => $card + [
                'code' => $product->sku,
                'gallery' => $gallery,
                'thumbs' => $gallery,
                'description' => $product->description,
                'breadcrumbs' => $product->category ? array_map(
                    fn (Category $c) => ['name' => $c->name, 'url' => route('catalog', $c->slug)],
                    $product->category->ancestorsAndSelf(),
                ) : [],
            ],
            'model' => $product,      // the Eloquent model for the Livewire components
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
        ];
    }

    /**
     * The colour / memory switchers on the product page: one entry per sibling
     * product, so choosing an option navigates to the product that has it.
     *
     * @return array<string, array<int, array{code:string,label:?string,hex:?string,url:?string,current:bool}>>
     */
    public static function variantSwitchers(Product $product): array
    {
        if (! $product->variant_group) {
            return [];
        }

        $siblings = Product::active()->withTranslation()
            ->where('variant_group', $product->variant_group)
            ->with(['attributeValues' => fn ($q) => $q->withTranslation()
                ->whereHas('attribute', fn ($a) => $a->where('is_variant', true))
                ->with('attribute')])
            ->orderBy('price')
            ->get();

        $switchers = [];

        foreach ($siblings as $sibling) {
            $isCurrent = $sibling->is($product);

            foreach ($sibling->attributeValues as $value) {
                if (! $value->attribute) {
                    continue;
                }

                $code = $value->attribute->code;

                // the first sibling carrying an option owns it, unless the option
                // belongs to the product being viewed — that one always wins
                if ($isCurrent || ! isset($switchers[$code][$value->code])) {
                    $switchers[$code][$value->code] = [
                        'code' => $value->code,
                        'label' => $value->label,
                        'hex' => $value->color_hex,
                        'url' => $isCurrent ? null : route('product', $sibling->slug),
                        'current' => $isCurrent,
                    ];
                }
            }
        }

        return array_map('array_values', $switchers);
    }

    /* ================================================================== header search */

    /** Header search: translated name or summary, brand name, or sku. */
    public static function search(string $term, int $limit = 5): array
    {
        $products = self::productQuery()
            ->where(function ($q) use ($term) {
                $q->whereHas('translations', fn ($t) => $t
                    ->where('locale', app()->getLocale())
                    ->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                        ->orWhere('summary', 'like', "%{$term}%")))
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$term}%"));
            })
            ->orderByDesc('sales_count')
            ->limit($limit)
            ->get();

        return self::cards($products);
    }
}
