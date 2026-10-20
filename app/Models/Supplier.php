<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['code', 'name', 'driver', 'is_active', 'priority', 'config', 'markup', 'last_run_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'config' => 'array',
            'markup' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('priority');
    }

    public function markup(float $cost): float
    {
        foreach ($this->markup ?? [] as $tier) {
            if (! isset($tier['up_to']) || $cost <= (float) $tier['up_to']) {
                return isset($tier['percent'])
                    ? self::toLari($cost * (1 + (float) $tier['percent'] / 100))
                    : self::toLari($cost + (float) ($tier['add'] ?? 0));
            }
        }

        return self::toLari($cost);
    }

    /* Shelf prices are whole lari. The page, the feed, the pixel and the bank
       then all say the same number instead of rounding one for display. */
    public static function toLari(float $amount): float
    {
        return round($amount, 0);
    }
}
