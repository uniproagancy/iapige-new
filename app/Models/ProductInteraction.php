<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductInteraction extends Model
{
    public const UPDATED_AT = null;   // append-only

    public const CART_ADD = 'cart_add';

    public const CART_REMOVE = 'cart_remove';

    public const WISH_ADD = 'wish_add';

    public const WISH_REMOVE = 'wish_remove';

    protected $fillable = ['user_id', 'session_id', 'product_id', 'action', 'qty', 'price'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
