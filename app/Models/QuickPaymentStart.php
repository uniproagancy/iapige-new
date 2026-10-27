<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickPaymentStart extends Model
{
    protected $fillable = ['order_id', 'driver', 'request_hash', 'options', 'amount', 'status',
        'payment_transaction_id', 'failure_class', 'started_at'];

    protected function casts(): array
    {
        return ['options' => 'array', 'amount' => 'decimal:2', 'started_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}
