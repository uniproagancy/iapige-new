<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryRate extends Model
{
    protected $fillable = ['delivery_city_id', 'up_to_weight', 'fee'];

    protected function casts(): array
    {
        return ['up_to_weight' => 'integer', 'fee' => 'decimal:2'];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(DeliveryCity::class, 'delivery_city_id');
    }
}