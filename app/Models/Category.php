<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Category extends Model
{
    use HasTranslations;

    protected array $translatable = ['name', 'slug', 'description', 'meta_title', 'meta_description'];

    protected $fillable = [
        'parent_id', 'image', 'icon', 'is_active', 'show_on_home', 'sort_order',
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
        $ids = [$this->getKey()];
        $level = [$this->getKey()];

        while ($level) {
            $level = static::whereIn('parent_id', $level)->pluck('id')->all();
            $ids = array_merge($ids, $level);
        }

        return $ids;
    }

    public function ancestorsAndSelf(): array
    {
        $trail = [$this];
        $node = $this;

        while ($node->parent_id && ($node = $node->parent)) {
            array_unshift($trail, $node);
        }

        return $trail;
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
