<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string|null $name
 */
class Promotion extends Model
{
    use HasTranslations;

    protected array $translatable = ['title', 'subtitle', 'cta'];

    protected $fillable = [
        'code', 'type', 'image', 'url', 'badge', 'badge_color',
        'is_active', 'starts_at', 'ends_at', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /**
     * The products in this campaign.
     *
     * The pivot is named, because Laravel would otherwise work it out
     * alphabetically as product_promotion while the migration created
     * promotion_product — and this relation's own ordering clause already said
     * so, which is how a table that does not exist came to be queried.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_product')
            // discount_percent and stock_limit exist in the pivot and were not
            // read here, so a campaign set up by percentage showed no discount
            ->withPivot(['promo_price', 'discount_percent', 'stock_limit', 'sold', 'sort_order'])
            ->withTimestamps()
            ->orderBy('promotion_product.sort_order');
    }

    /** Running right now. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
