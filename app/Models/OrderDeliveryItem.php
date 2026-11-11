<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDeliveryItem extends Model
{
    protected $fillable = [
        'order_delivery_id', 'order_item_id', 'product_id', 'sku', 'name',
        'qty', 'price', 'sum', 'supplier_id', 'supplier_name', 'supplier_sku',
        'warehouse', 'pickup_address', 'is_manual', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'price' => 'decimal:2',
            'sum' => 'decimal:2',
            'is_manual' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(OrderDelivery::class, 'order_delivery_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
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
