<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'number', 'user_id', 'cart_id', 'status',
        'name', 'phone', 'email',
        'delivery', 'delivery_city_id', 'city', 'address', 'comment',
        'payment', 'payment_status', 'is_paid', 'paid_at', 'installment_months',
        'subtotal', 'shipping', 'total',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping' => 'decimal:2',
            'total' => 'decimal:2',
            'is_paid' => 'boolean',
            'paid_at' => 'datetime',
            'installment_months' => 'integer',
        ];
    }

    public function deliveryCity(): BelongsTo
    {
        return $this->belongsTo(DeliveryCity::class, 'delivery_city_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function nextNumber(): string
    {
        $today = now()->format('ymd');
        $count = static::whereDate('created_at', today())->count() + 1;

        return sprintf('IAPI-%s-%04d', $today, $count);
    }
}
