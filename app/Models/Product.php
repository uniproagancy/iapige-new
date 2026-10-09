<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string|null $name
 * @property string|null $slug
 * @property string|null $summary
 * @property string|null $description
 * @property string $price decimal:2
 * @property string|null $old_price decimal:2
 */
class Product extends Model
{
    use HasTranslations, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected array $translatable = ['name', 'slug', 'summary', 'description', 'meta_title', 'meta_description'];

    /*
     | Every column the admin form and the importer actually write.
     |
     | cost_price, price_lock and taxonomy_lock were missing, and fill() drops
     | what it is not told about without a word — so the two switches that exist
     | precisely to stop the importer overwriting a human's decision could never
     | be saved from the product form. Ticking "keep my price" appeared to work
     | and the next run replaced the price anyway.
     */
    protected $fillable = [
        'category_id', 'brand_id', 'sku', 'price', 'old_price', 'cost_price',
        'price_lock', 'taxonomy_lock', 'stock', 'status',
        'is_new', 'is_featured', 'rating', 'reviews_count', 'sales_count', 'published_at',
        'weight', 'length', 'width', 'height', 'is_bulky', 'variant_group', 'is_preorder', 'release_date', 'prepay_percent',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'old_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'price_lock' => 'boolean',
            'taxonomy_lock' => 'boolean',
            'stock' => 'integer',
            'is_new' => 'boolean',
            'is_featured' => 'boolean',
            'rating' => 'decimal:1',
            'reviews_count' => 'integer',
            'sales_count' => 'integer',
            'published_at' => 'datetime',
            'is_preorder' => 'boolean',
            'release_date' => 'date',
            'prepay_percent' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ relations */

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** select / colour attribute values — what the catalog filters match on */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class);
    }

    /** the spec table on the product page */
    public function specs(): HasMany
    {
        return $this->hasMany(ProductSpec::class)->orderBy('sort_order');
    }

    /* ------------------------------------------------------------------ scopes */

    /** Visible on the storefront. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /**
     * Published, and actually obtainable.
     *
     * Browsing a shop and finding nothing but things it does not have wastes
     * the visit, so a product with an empty shelf stays out of the listings.
     *
     * A pre-order is the exception and always has been: it has no stock by
     * definition, and its whole point is to be seen before it arrives.
     *
     * Separate from active() on purpose. A direct link — a shared one, a
     * bookmark, a search result — still opens the product's page, where it
     * says it is unavailable rather than answering with a 404.
     */
    public function scopeListable(Builder $query): Builder
    {
        return $query->active()
            ->where(fn (Builder $q) => $q->where('stock', '>', 0)->orWhere('is_preorder', true));
    }

    /**
     * Has everything publishing requires: a category, a name and a price.
     *
     * These are exactly what the admin's publish button refuses without, so the
     * rule lives here once and both the button and the list's filter read it
     * from this one place. Two copies would mean a filter that counts products
     * the button then rejects.
     *
     * No status condition: publishing an archived product is a decision the
     * admin is allowed to make. The list's "ready" filter adds `draft` itself,
     * because there the question is what is still waiting.
     */
    /**
     * What this product actually sells for right now.
     *
     * The shop had two answers to that question. The deals rail worked out a
     * campaign price and showed it; the cart, the checkout and the order read
     * products.price and knew nothing about campaigns — so a customer was
     * shown one figure on the front page and charged another at the till.
     *
     * One answer now, and every place that handles money asks it. A product
     * that is already marked down and simply placed in a campaign keeps its
     * own price, which is the common case: the campaign is a shelf, not
     * necessarily a further cut.
     */
    public function sellingPrice(): float
    {
        return $this->promoPrice() ?? (float) $this->price;
    }

    /**
     * The campaign price, when a campaign running now sets one.
     *
     * Reads the eager-loaded relation when there is one. Every card asks this,
     * so going to the database per product would turn one category page into a
     * query for each row on it.
     */
    public function promoPrice(): ?float
    {
        $promotion = $this->relationLoaded('livePromotions')
            ? $this->livePromotions->first()
            : $this->livePromotions()->orderBy('promotions.sort_order')->first();

        return $promotion ? static::promoFrom($promotion->pivot, (float) $this->price) : null;
    }

    /**
     * One rule, read from the pivot, used by the storefront and the cart alike.
     *
     * A figure that is not below the shelf price is no offer — there would be
     * nothing to strike through — so it is refused here rather than quietly
     * charged.
     */
    public static function promoFrom(?object $pivot, float $price): ?float
    {
        if (! $pivot) {
            return null;
        }

        $promo = match (true) {
            (float) ($pivot->promo_price ?? 0) > 0 => (float) $pivot->promo_price,
            (int) ($pivot->discount_percent ?? 0) > 0 => round($price * (1 - $pivot->discount_percent / 100), 2),
            default => null,
        };

        return $promo !== null && $promo > 0 && $promo < $price ? $promo : null;
    }

    /**
     * The campaigns running right now, ready to be eager-loaded.
     *
     * A constrained relation rather than a closure on with(), because the
     * card's relation list is a constant and a constant cannot hold one.
     */
    public function livePromotions(): BelongsToMany
    {
        return $this->promotions()->live()->orderBy('promotions.sort_order');
    }

    /** The campaigns this product is part of. */
    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class, 'promotion_product')
            ->withPivot(['promo_price', 'discount_percent', 'stock_limit', 'sold', 'sort_order']);
    }

    public function scopeReadyToPublish(Builder $query): Builder
    {
        $locales = array_values(array_unique([app()->getLocale(), Language::defaultCode()]));

        return $query->whereNotNull('category_id')
            ->where('price', '>', 0)
            // $product->name falls back from the current locale to the default one
            ->whereHas('translations', fn (Builder $q) => $q
                ->whereIn('locale', $locales)
                ->whereNotNull('name')
                ->where('name', '!=', ''));
    }

    /** Products carrying an attribute value, e.g. ->withAttributeValue('ram', ['16gb', '32gb']). */
    public function scopeWithAttributeValue(Builder $query, string $attribute, string|array $codes): Builder
    {
        return $query->whereHas('attributeValues', fn (Builder $q) => $q
            ->whereIn('attribute_values.code', (array) $codes)
            ->whereHas('attribute', fn (Builder $a) => $a->where('code', $attribute)));
    }

    /* ------------------------------------------------------------------ helpers */

    public function discountPercent(): ?int
    {
        if (! $this->old_price || (float) $this->old_price <= (float) $this->price) {
            return null;
        }

        return (int) round((1 - (float) $this->price / (float) $this->old_price) * 100);
    }

    /** The instalment figure follows the price actually charged. */
    public function monthlyPrice(int $months = 12): int
    {
        return (int) round($this->sellingPrice() / $months);
    }

    public function inStock(): bool
    {
        return $this->stock > 0;
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class);
    }

    /**
     * Whether it can go in a basket right now.
     *
     * Stock counts here too. Without it a product kept out of every listing
     * was still buyable to anyone holding its address, and the shop took
     * money for something it had already said it did not have.
     */
    public function isSellable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->is_preorder && $this->stock > 0;
    }

    /**
     * Whether the page may say "in stock".
     *
     * This read the status alone, so the "not available" line on the product
     * page — markup, styles and translation all written — could never be
     * reached by an active product.
     */
    public function isListed(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ($this->stock > 0 || $this->is_preorder);
    }

    public function prepayment(): ?float
    {
        return $this->is_preorder && $this->prepay_percent
            ? round((float) $this->price * $this->prepay_percent / 100, 2)
            : null;
    }
}
