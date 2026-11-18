<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementItem extends Model
{
    protected $guarded = ['id'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(OrderDeliveryItem::class, 'order_delivery_item_id');
    }

    protected function casts(): array
    {
        return [
            'source_snapshot' => 'array',
            'last_sent_snapshot' => 'array',
            'version' => 'integer',
            'sent_version' => 'integer',
            'withdrawn_sent_version' => 'integer',
            'issue_qty' => 'integer',
        ];
    }
}
