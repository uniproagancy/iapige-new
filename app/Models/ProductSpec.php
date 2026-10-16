<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpec extends Model
{
    use HasTranslations;

    protected array $translatable = ['value'];

    protected $fillable = ['product_id', 'attribute_id', 'is_key', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_key' => 'boolean',
            'is_manual' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
