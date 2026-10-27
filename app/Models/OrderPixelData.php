<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPixelData extends Model
{
    protected $table = 'order_pixel_data';

    protected $fillable = [
        'order_id', 'event_id', 'fbp', 'fbc', 'ip', 'user_agent', 'source_url', 'consented', 'sent_at',
        'external_id', 'occurred_at', 'action_source', 'custom_data', 'user_data', 'browser_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'consented' => 'boolean',
            'sent_at' => 'datetime',
            'occurred_at' => 'datetime',
            'browser_sent_at' => 'datetime',
            'custom_data' => 'array',
            'user_data' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function markSent(): void
    {
        $this->forceFill(['sent_at' => now()])->save();
    }

    public function alreadySent(): bool
    {
        return $this->sent_at !== null;
    }
}
