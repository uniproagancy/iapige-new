<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the product spec table. The label is the attribute's name,
 * the value is translated here: "პროცესორი — Ryzen 9 7940HS · 8 ბირთვი".
 *
 * @property string|null $value
 */
class ProductSpec extends Model
{
    use HasTranslations;

    protected array $translatable = ['value'];

    protected $fillable = ['product_id', 'attribute_id', 'is_key', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_key'     => 'boolean',
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
