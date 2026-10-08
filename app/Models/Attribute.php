<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $name
 */
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

    public function scopeFilterable(Builder $query): Builder
    {
        return $query->where('is_filterable', true)->orderBy('sort_order');
    }

    public function hasValues(): bool
    {
        return $this->type !== self::TYPE_TEXT;
    }
}
