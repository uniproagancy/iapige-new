<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $name
 */
class DeliveryCity extends Model
{
    use HasTranslations;

    protected array $translatable = ['name'];

    protected $fillable = ['fee', 'free_from', 'days', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'free_from' => 'decimal:2',
            'days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(DeliveryRate::class)->orderBy('up_to_weight');
    }

    public function feeFor(): float
    {
        return (float) $this->fee;
    }
}
