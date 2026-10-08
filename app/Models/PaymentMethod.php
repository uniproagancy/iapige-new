<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * @property string|null $name
 * @property string|null $note
 */
class PaymentMethod extends Model
{
    use HasTranslations;

    protected array $translatable = ['name', 'note'];

    protected $fillable = ['code', 'driver', 'logo', 'is_active', 'is_online', 'min_total', 'max_total', 'sort_order', 'config'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_online' => 'boolean',
            'min_total' => 'decimal:2',
            'max_total' => 'decimal:2',
            'sort_order' => 'integer',
            'config' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Instalments below their floor (or above their ceiling) must not be offered. */
    public function fitsTotal(float $total): bool
    {
        return ($this->min_total === null || $total >= (float) $this->min_total)
            && ($this->max_total === null || $total <= (float) $this->max_total);
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo) {
            return null;
        }

        return str_starts_with($this->logo, 'http') || str_starts_with($this->logo, '/')
            ? $this->logo
            : Storage::url($this->logo);
    }
}
