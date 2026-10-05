<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One supplier's price and stock for one product. */
class ProductOffer extends Model
{
    protected $fillable = [
        'product_id', 'supplier_id', 'external_id',
        'cost_price', 'old_cost_price', 'stock', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_price'     => 'decimal:2',
            'old_cost_price' => 'decimal:2',
            'stock'          => 'integer',
            'synced_at'      => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
