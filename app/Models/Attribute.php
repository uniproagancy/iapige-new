<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    use HasTranslations;

    public const TYPE_SELECT = 'select';

    public const TYPE_COLOR = 'color';

    public const TYPE_TEXT = 'text';

    protected array $translatable = ['name'];

    protected $fillable = ['code', 'type', 'unit', 'is_filterable', 'is_variant', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'is_variant' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order');
    }

    /*
     | The shelves this attribute belongs on. Empty means all of them, which is
     | how every attribute stood before there was a choice.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /*
     | Whether this attribute is offered under a given category -- which counts
     | a parent's listing for its children, so "Mobile Phones & Accessories"
     | covers the phones beneath it without naming each one.
     */
    public function appliesTo(?Category $category): bool
    {
        $only = $this->relationLoaded('categories')
            ? $this->categories->pluck('id')
            : $this->categories()->pluck('categories.id');

        if ($only->isEmpty()) {
            return true;
        }

        if (! $category) {
            return false;
        }

        $line = collect($category->ancestorsAndSelf())->pluck('id');

        return $only->intersect($line)->isNotEmpty();
    }

    public function scopeFilterable(Builder $query): Builder
    {
        return $query->where('is_filterable', true)->orderBy('sort_order');
    }

    public function hasValues(): bool
    {
        return $this->type !== self::TYPE_TEXT;
    }
}
