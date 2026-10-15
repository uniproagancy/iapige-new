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

    /**
     * The product this line was for, if it is still there.
     *
     * The line keeps its own name, sku and price precisely so an order reads
     * the same years later whatever happens to the catalogue — so this is for
     * the things a line does not carry, the photograph above all. It may be
     * null: a product deleted since is a line that stands on its own.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
