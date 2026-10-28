<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use SoftDeletes;

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

    public const NEW = 'new';

    public const VIEWED = 'viewed';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';
	
	public const NOANSWER = 'noanswer';
	
	public const CALLLATER = 'calllater';

    /* The four states the shop actually works in. Everything that used to sit
       between them — confirmed, packed, shipped — said the same thing to the
       people using it: somebody has picked this up. */
    public const STATUSES = [self::NEW, self::VIEWED, self::COMPLETED, self::CANCELLED, self::NOANSWER, self::CALLLATER];

    /* Still on somebody's desk. */
    public const OPEN = [self::NEW, self::VIEWED];

    /* Done with, one way or the other. */
    public const ARCHIVED = [self::COMPLETED, self::CANCELLED];

    public static function labels(): array
    {
        return [
            self::NEW => __('order.status.new'),
            self::VIEWED => __('order.status.viewed'),
            self::COMPLETED => __('order.status.completed'),
            self::CANCELLED => __('order.status.cancelled'),
            self::NOANSWER => __('order.status.noanswer'),
            self::CALLLATER => __('order.status.calllater'),
        ];
    }

    public function isArchived(): bool
    {
        return in_array($this->status, self::ARCHIVED, true);
    }

    public function deliveryCity(): BelongsTo
    {
        return $this->belongsTo(DeliveryCity::class, 'delivery_city_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        /* The number is the row's own id, so deleting an order can never hand
           its number to the next one. The id is only known once the row is
           written, so it goes in with a placeholder and is stamped after. */
        static::creating(function (self $order) {
            if (! $order->number) {
                $order->number = static::placeholder();
            }
        });

        static::created(function (self $order) {
            if (str_starts_with((string) $order->number, self::TEMPORARY)) {
                $order->forceFill(['number' => $order->buildNumber()])->saveQuietly();
            }
        });
    }

    public const TEMPORARY = 'TMP-';

    public static function placeholder(): string
    {
        return self::TEMPORARY.Str::upper(Str::random(12));
    }

    public function buildNumber(): string
    {
        return sprintf('IAPI-%s-%05d', ($this->created_at ?? now())->format('ymd'), $this->getKey());
    }

    /* Kept so the places that ask for a number still work; the real one is
       stamped on by the model once the row exists. */
    public static function nextNumber(): string
    {
        return static::placeholder();
    }
}
