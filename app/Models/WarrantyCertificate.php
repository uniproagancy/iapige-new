<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class WarrantyCertificate extends Model
{
    public const DRAFT = 'draft';
    public const ISSUED = 'issued';
    public const REVOKED = 'revoked';

    protected $guarded = ['id'];
    protected $hidden = ['snapshot', 'access_token', 'access_token_hash', 'customer_phone', 'pdf_path', 'serial_fingerprint',
        'sale_date_override_reason', 'supplier_native_sku_reference', 'supplier_native_sku_reason', 'supplier_native_sku_identity_sha256'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'unit_index' => 'integer', 'manual_term_value' => 'integer',
            'sale_date_source_event_id' => 'integer', 'snapshot' => 'encrypted:array',
            'customer_name' => 'encrypted', 'customer_phone' => 'encrypted', 'access_token' => 'encrypted',
            'sale_date' => 'date', 'delivery_date' => 'date', 'manufacturer_date' => 'date',
            'actual_supplier_confirmed' => 'boolean', 'delivery_date_confirmed' => 'boolean',
            'issued_at' => 'datetime', 'revoked_at' => 'datetime', 'public_enabled_at' => 'datetime', 'last_sms_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $certificate): void {
            if ($certificate->getRawOriginal('status') !== self::DRAFT && $certificate->isDirty('status')
                && ! ($certificate->getRawOriginal('status') === self::ISSUED && $certificate->status === self::REVOKED)) {
                throw ValidationException::withMessages(['certificate' => __('warranty.errors.certificate_immutable')]);
            }
            if ($certificate->getRawOriginal('status') !== self::DRAFT
                && array_diff(array_keys($certificate->getDirty()), ['status', 'revoked_at', 'revoked_by', 'revocation_reason',
                    'access_token', 'access_token_hash', 'public_enabled_at', 'last_sms_at', 'updated_at', 'updated_by']) !== []) {
                throw ValidationException::withMessages(['certificate' => __('warranty.errors.certificate_immutable')]);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['certificate' => __('warranty.errors.certificate_immutable')]));
    }

    public function policy(): BelongsTo { return $this->belongsTo(WarrantyPolicy::class, 'policy_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class)->withTrashed(); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class)->withTrashed(); }
    public function deliveryItem(): BelongsTo { return $this->belongsTo(OrderDeliveryItem::class, 'order_delivery_item_id'); }
    public function documents(): HasMany { return $this->hasMany(WarrantyDocument::class, 'certificate_id'); }
    public function events(): HasMany { return $this->hasMany(WarrantyEvent::class, 'certificate_id'); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
    public function previousCertificate(): BelongsTo { return $this->belongsTo(self::class, 'previous_certificate_id'); }
}
