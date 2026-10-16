<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasTranslations, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected array $translatable = ['name', 'slug', 'summary', 'description', 'meta_title', 'meta_description'];

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

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class);
    }

    public function specs(): HasMany
    {
        return $this->hasMany(ProductSpec::class)->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function scopeListable(Builder $query): Builder
    {
        return $query->active()
            ->where(fn (Builder $q) => $q->where('stock', '>', 0)->orWhere('is_preorder', true));
    }

    public function sellingPrice(): float
    {
        return $this->promoPrice() ?? (float) $this->price;
    }

    public function promoPrice(): ?float
    {
        $promotion = $this->relationLoaded('livePromotions')
            ? $this->livePromotions->first()
            : $this->livePromotions()->orderBy('promotions.sort_order')->first();

        return $promotion ? static::promoFrom($promotion->pivot, (float) $this->price) : null;
    }

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

    public function livePromotions(): BelongsToMany
    {
        return $this->promotions()->live()->orderBy('promotions.sort_order');
    }

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

            ->whereHas('translations', fn (Builder $q) => $q
                ->whereIn('locale', $locales)
                ->whereNotNull('name')
                ->where('name', '!=', ''));
    }

    public function scopeWithAttributeValue(Builder $query, string $attribute, string|array $codes): Builder
    {
        return $query->whereHas('attributeValues', fn (Builder $q) => $q
            ->whereIn('attribute_values.code', (array) $codes)
            ->whereHas('attribute', fn (Builder $a) => $a->where('code', $attribute)));
    }

    public function discountPercent(): ?int
    {
        if (! $this->old_price || (float) $this->old_price <= (float) $this->price) {
            return null;
        }

        return (int) round((1 - (float) $this->price / (float) $this->old_price) * 100);
    }

    public function monthlyPrice(?int $months = null): int
    {
        $months = max(1, $months ?? (int) config('shop.instalment_months', 24));

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

    public function isSellable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->is_preorder && $this->stock > 0;
    }

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
