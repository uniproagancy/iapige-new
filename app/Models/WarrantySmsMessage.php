<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantySmsMessage extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['recipient', 'message', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted', 'message' => 'encrypted',
            'attempted_at' => 'datetime', 'accepted_at' => 'datetime',
        ];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(WarrantyCertificate::class, 'warranty_certificate_id');
    }
}
