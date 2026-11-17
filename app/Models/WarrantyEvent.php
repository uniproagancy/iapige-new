<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class WarrantyEvent extends Model
{
    public const UPDATED_AT = null;
    protected $guarded = ['id'];
    protected function casts(): array { return ['metadata' => 'array']; }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['event' => __('warranty.errors.event_immutable')]));
        static::deleting(fn () => throw ValidationException::withMessages(['event' => __('warranty.errors.event_immutable')]));
    }

    public function certificate(): BelongsTo { return $this->belongsTo(WarrantyCertificate::class, 'certificate_id'); }
    public function policy(): BelongsTo { return $this->belongsTo(WarrantyPolicy::class, 'policy_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
