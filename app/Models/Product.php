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
 * @property string      $price       decimal:2
 * @property string|null $old_price   decimal:2
 */
class Product extends Model
{
    use HasTranslations, SoftDeletes;

    public const STATUS_DRAFT    = 'draft';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected array $translatable = ['name', 'slug', 'summary', 'description', 'meta_title', 'meta_description'];

    protected $fillable = [
        'category_id', 'brand_id', 'sku', 'price', 'old_price', 'stock', 'status',
        'is_new', 'is_featured', 'rating', 'reviews_count', 'sales_count', 'published_at',
		'weight', 'length', 'width', 'height', 'is_bulky','variant_group', 'is_preorder', 'release_date', 'prepay_percent'
    ];

    protected function casts(): array
    {
        return [
            'price'         => 'decimal:2',
            'old_price'     => 'decimal:2',
            'stock'         => 'integer',
            'is_new'        => 'boolean',
            'is_featured'   => 'boolean',
            'rating'        => 'decimal:1',
            'reviews_count' => 'integer',
            'sales_count'   => 'integer',
            'published_at'  => 'datetime',
			'is_preorder'    => 'boolean',
            'release_date'   => 'date',
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

    public function monthlyPrice(int $months = 12): int
    {
        return (int) round((float) $this->price / $months);
    }

    public function inStock(): bool
    {
        return $this->stock > 0;
    }
	
	public function offers(): hasMany
    {
        return $this->hasMany(ProductOffer::class);
    }
	
	public function isSellable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->is_preorder;
    }

    public function isListed(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function prepayment(): ?float
    {
        return $this->is_preorder && $this->prepay_percent
            ? round((float) $this->price * $this->prepay_percent / 100, 2)
            : null;
    }
}
