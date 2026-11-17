<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class WarrantyPolicy extends Model
{
    public const DRAFT = 'draft';
    public const APPROVED = 'approved';
    public const ARCHIVED = 'archived';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'term_value' => 'integer', 'source_confirmed' => 'boolean',
            'requires_original' => 'boolean', 'valid_from' => 'date', 'valid_until' => 'date', 'approved_at' => 'datetime',
            'order_delivery_item_id' => 'integer', 'approval_context' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $policy): void {
            if ($policy->getRawOriginal('status') !== self::DRAFT && $policy->isDirty('status')
                && ! ($policy->getRawOriginal('status') === self::APPROVED && $policy->status === self::ARCHIVED)) {
                throw ValidationException::withMessages(['policy' => __('warranty.errors.policy_immutable')]);
            }
            if ($policy->getRawOriginal('status') !== self::DRAFT
                && array_diff(array_keys($policy->getDirty()), ['status', 'updated_at', 'updated_by']) !== []) {
                throw ValidationException::withMessages(['policy' => __('warranty.errors.policy_immutable')]);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['policy' => __('warranty.errors.policy_immutable')]));
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function documents(): HasMany { return $this->hasMany(WarrantyDocument::class, 'policy_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function events(): HasMany { return $this->hasMany(WarrantyEvent::class, 'policy_id'); }
}
