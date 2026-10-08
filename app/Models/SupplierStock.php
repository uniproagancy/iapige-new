<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierStock extends Model
{
    protected $fillable = ['supplier_id', 'external_id', 'quantity', 'cost_price', 'data', 'synced_at'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'cost_price' => 'decimal:2',
            'data' => 'array',        // ← ეს აკლია
            'synced_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
