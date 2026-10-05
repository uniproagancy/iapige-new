<?php

namespace App\Models;

use App\Models\DeliveryCity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = [
        'number', 'user_id', 'cart_id', 'status',
        'name', 'phone', 'email',
        'delivery', 'city', 'address', 'comment',
        'payment', 'subtotal', 'shipping', 'total',
    ];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'shipping' => 'decimal:2', 'total' => 'decimal:2'];
    }
	
	public function city(): BelongsTo
    {
        return $this->belongsTo(DeliveryCity::class, 'city_id');
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

        return sprintf('ELIO-%s-%04d', $today, $count);
    }
}