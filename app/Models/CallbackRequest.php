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
    ];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
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

    /** The order an operator wrote from this call, if it came to one. */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * What the browser knew when the number was left.
     *
     * Handed to the Purchase the operator writes later, which happens in the
     * admin where none of it can be read any more.
     */
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
