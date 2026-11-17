<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class WarrantyDocument extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['path'];
    protected function casts(): array { return ['bytes' => 'integer']; }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['document' => __('warranty.errors.document_immutable')]));
        static::deleting(fn () => throw ValidationException::withMessages(['document' => __('warranty.errors.document_immutable')]));
    }

    public function policy(): BelongsTo { return $this->belongsTo(WarrantyPolicy::class, 'policy_id'); }
    public function certificate(): BelongsTo { return $this->belongsTo(WarrantyCertificate::class, 'certificate_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
