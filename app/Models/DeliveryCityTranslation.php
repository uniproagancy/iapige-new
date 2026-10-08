<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryCityTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['locale', 'name'];

    public function deliveryCity(): BelongsTo
    {
        return $this->belongsTo(DeliveryCity::class, 'delivery_city_id');
    }
}
