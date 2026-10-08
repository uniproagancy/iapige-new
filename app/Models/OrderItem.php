<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'product_id', 'sku', 'name', 'price', 'qty', 'sum'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sum' => 'decimal:2',
            'qty' => 'integer',
            'is_preorder' => 'boolean',
            'release_date' => 'date',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
