<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialPost extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_READY, self::STATUS_PUBLISHING,
        self::STATUS_PUBLISHED, self::STATUS_FAILED, self::STATUS_UNKNOWN, self::STATUS_SKIPPED,
    ];

    protected $fillable = [
        'page_id', 'product_id', 'scheduled_for', 'scheduled_date', 'status', 'theme',
        'snapshot', 'caption', 'image_path', 'post_id', 'error', 'claimed_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'snapshot' => 'array',
        ];
    }

    protected function scheduledFor(): Attribute
    {
        return $this->utcDateTime();
    }

    protected function claimedAt(): Attribute
    {
        return $this->utcDateTime();
    }

    protected function publishedAt(): Attribute
    {
        return $this->utcDateTime();
    }

    protected function createdAt(): Attribute
    {
        return $this->utcDateTime();
    }

    protected function updatedAt(): Attribute
    {
        return $this->utcDateTime();
    }

    protected function utcDateTime(): Attribute
    {
        // The ledger stores UTC instants even when PHP and the storefront use Tbilisi time.
        return Attribute::make(
            get: static fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC'),
            set: static function ($value) {
                if ($value === null) {
                    return null;
                }

                $date = $value instanceof DateTimeInterface
                    ? CarbonImmutable::instance($value)
                    : CarbonImmutable::parse($value, 'UTC');

                return $date->utc()->format('Y-m-d H:i:s');
            },
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(SocialPublication::class);
    }
}
