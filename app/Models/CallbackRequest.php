<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallbackRequest extends Model
{
    public const NEW = 'new';

    public const CALLED = 'called';

    public const CLOSED = 'closed';

    protected $fillable = [
        'user_id', 'product_id', 'name', 'phone', 'comment',
        'status', 'note', 'handled_by', 'handled_at', 'page',
        'lead_event_id', 'fbp', 'fbc', 'ip', 'user_agent', 'source_url', 'consented',
        'order_id',
        'submission_key', 'submission_owner', 'request_hash',
        'product_name', 'product_sku', 'quantity', 'unit_price', 'total', 'currency',
        'payment_category', 'payment_method', 'external_id', 'checkout_event_id', 'checkout_started_at', 'checkout_data',
    ];

    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime', 'checkout_started_at' => 'datetime',
            'consented' => 'boolean', 'quantity' => 'integer',
            'checkout_data' => 'array',
            'unit_price' => 'decimal:2', 'total' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::NEW;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method', 'code');
    }

    public function pixelData(): array
    {
        return array_filter([
            'client_ip_address' => $this->ip,
            'client_user_agent' => $this->user_agent,
            'fbp' => $this->fbp,
            'fbc' => $this->fbc,
        ]);
    }
}
