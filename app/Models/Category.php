<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Support\Catalog;
use App\Support\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Category extends Model
{
    use HasTranslations;

    protected static function booted(): void
    {
        static::saved(function (self $category) {
            if ($category->wasChanged('parent_id') || $category->wasRecentlyCreated) {
                CategoryTree::forget();
            }

            if ($category->wasChanged(['is_active', 'parent_id', 'show_in_menu', 'show_on_home'])) {
                Catalog::forgetStocked();
            }
        });

        static::deleted(function () {
            CategoryTree::forget();
            Catalog::forgetStocked();
        });
    }

    protected array $translatable = ['name', 'slug', 'description', 'meta_title', 'meta_description'];

    protected $fillable = [
        'parent_id', 'image', 'icon', 'is_active', 'show_on_home', 'show_in_menu', 'sort_order',
        'in_feed', 'google_category_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_on_home' => 'boolean',
            'sort_order' => 'integer',
            'in_feed' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id')->orderBy('sort_order');
    }

    public function descendantAndSelfIds(): array
    {
        return CategoryTree::descendantAndSelfIds((int) $this->getKey());
    }

    public function ancestorsAndSelf(): array
    {
        return [...CategoryTree::ancestorModels((int) $this->getKey()), $this];
    }

    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return str_starts_with($this->image, 'http') || str_starts_with($this->image, '/')
            ? $this->image
            : Storage::url($this->image);
    }
}
