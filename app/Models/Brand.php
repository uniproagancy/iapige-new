<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Brand extends Model
{
    protected $fillable = ['name', 'slug', 'logo', 'is_active', 'is_featured', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /** Absolute URLs and /public paths are used as-is; anything else is a storage path. */
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
