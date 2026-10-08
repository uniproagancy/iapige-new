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

    /**
     * Selling price for a cost price, using this supplier's tiers:
     *     [{"up_to": 100, "add": 30}, {"up_to": 500, "add": 50}, {"add": 100}]
     * A tier without "up_to" is the catch-all; "percent" works instead of "add".
     */
    public function markup(float $cost): float
    {
        foreach ($this->markup ?? [] as $tier) {
            if (! isset($tier['up_to']) || $cost <= (float) $tier['up_to']) {
                return isset($tier['percent'])
                    ? round($cost * (1 + (float) $tier['percent'] / 100), 2)
                    : round($cost + (float) ($tier['add'] ?? 0), 2);
            }
        }

        return $cost;
    }
}
