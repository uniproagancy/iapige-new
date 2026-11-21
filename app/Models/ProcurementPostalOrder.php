<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementPostalOrder extends Model
{
    protected $guarded = ['id'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(OrderDelivery::class, 'order_id', 'order_id');
    }

    protected function casts(): array
    {
        return [
            'selected' => 'boolean', 'version' => 'integer',
            'selected_at' => 'datetime', 'picked_up_at' => 'datetime', 'uploaded_at' => 'datetime',
            'source_snapshot' => 'array', 'history' => 'array',
        ];
    }
}
