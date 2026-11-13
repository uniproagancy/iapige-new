<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderDelivery extends Model
{
    public const ACTIVE = 'active';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id', 'delivery_date', 'courier_id', 'status', 'is_eligible',
        'name', 'phone', 'city', 'address', 'customer_comment', 'payment', 'payment_bank',
        'subtotal', 'shipping', 'total', 'is_paid', 'paid_at', 'notes',
        'delivered_at', 'delivered_by',
        'picked_up_at', 'picked_up_by',
        'cash_received_at', 'cash_received_by', 'cash_received_amount',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'is_eligible' => 'boolean',
            'subtotal' => 'decimal:2',
            'shipping' => 'decimal:2',
            'total' => 'decimal:2',
            'is_paid' => 'boolean',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'cash_received_at' => 'datetime',
            'cash_received_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderDeliveryItem::class)->where('is_active', true)->orderBy('id');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(OrderDeliveryItem::class)->orderBy('id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function pickedUpBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_up_by');
    }

    public function cashReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_received_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->whereHas('order', fn (Builder $order) => $order->withoutTrashed());

        if ($user->isStaff()) {
            return $query;
        }

        return $user->role() === User::COURIER
            ? $query->where('courier_id', $user->id)->forCourierBoard()
            : $query->whereRaw('1 = 0');
    }

    public function scopeForCourierBoard(Builder $query): Builder
    {
        return $query->where(function (Builder $delivery) {
            $delivery->whereIn('status', [self::DELIVERED, self::CANCELLED])
                ->orWhere(function (Builder $active) {
                    $active->where('status', self::ACTIVE)
                        ->where('is_eligible', true)
                        ->whereHas('order', fn (Builder $order) => $order->where('status', Order::COMPLETED));
                });
        });
    }

    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->whereHas('order', fn (Builder $order) => $order->withoutTrashed()->where('status', '!=', Order::CANCELLED))
            ->where(function (Builder $delivery) {
                $delivery->where('is_eligible', false)
                    ->orWhereHas('order', fn (Builder $order) => $order->where('status', '!=', Order::COMPLETED));
            });
    }

    public function isHeld(): bool
    {
        if ($this->status !== self::ACTIVE) {
            return false;
        }
        $order = $this->order;

        return $order !== null && ! $order->trashed()
            && $order->status !== Order::CANCELLED
            && (! $this->is_eligible || $order->status !== Order::COMPLETED);
    }

    public function isEditable(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function canDeliver(): bool
    {
        return $this->isEditable() && $this->is_eligible;
    }

    public function requiresCashReceipt(): bool
    {
        return in_array($this->status, [self::ACTIVE, self::DELIVERED], true) && $this->is_eligible
            && $this->payment === 'cash' && ! $this->hasCashReceipt();
    }

    public function hasCashReceipt(): bool
    {
        return $this->cash_received_at !== null && $this->cash_received_amount !== null;
    }

    public function isPickedUp(): bool
    {
        return $this->picked_up_at !== null;
    }

    public function canPickUp(): bool
    {
        return $this->canDeliver() && ! $this->isPickedUp();
    }

    public function canCorrectActions(): bool
    {
        return $this->status === self::DELIVERED
            || ($this->status === self::ACTIVE && $this->isPickedUp());
    }

    public function amountDue(): ?string
    {
        if ($this->status === self::CANCELLED) {
            return '0.00';
        }
        if ($this->payment === 'cash') {
            return $this->hasCashReceipt() ? '0.00' : ($this->requiresCashReceipt() ? $this->total : null);
        }

        return $this->is_paid ? '0.00' : null;
    }
}
