<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Promotion extends Model
{
    use HasTranslations;

    protected array $translatable = ['title', 'subtitle', 'cta', 'slug'];

    protected $fillable = [
        'code', 'type', 'image', 'url', 'badge', 'badge_color',
        'is_active', 'starts_at', 'ends_at', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_product')

            ->withPivot(['promo_price', 'discount_percent', 'stock_limit', 'sold', 'sort_order'])
            ->withTimestamps()
            ->orderBy('promotion_product.sort_order');
    }

    public function urlKey(): string
    {
        return $this->slug ?: $this->code;
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
