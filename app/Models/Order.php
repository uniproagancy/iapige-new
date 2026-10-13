<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /**
     * Every column anything writes to, which it did not used to be.
     *
     * The payment columns were missing, so the four writes that settle a
     * payment — is_paid, paid_at, payment_status, installment_months — were
     * dropped on the way in without a word. Mass assignment discards what it
     * does not recognise silently, so the bank's confirmation came back, the
     * transaction was marked successful, the event was written, and the order
     * stayed unpaid. The admin's "mark as paid" button did the same nothing
     * and still said it had saved.
     *
     * Anything added to this table from here on belongs in this list too.
     */
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

    /**
     * The zone the order was priced for.
     *
     * Not called city(), although it was: "city" is also a column on this
     * table — the name, printed on the invoice and the emails — and an
     * attribute always wins over a relation of the same name. So $order->city
     * returned the text and the relation could never load, whatever key it was
     * given. The text stays where every view and the invoice already read it;
     * this is for recalculating a fee or counting orders by zone.
     */
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
