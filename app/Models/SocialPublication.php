<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPublication extends Model
{
    public const ORIGIN_AUTOMATIC = 'automatic';

    public const ORIGIN_MANUAL = 'manual';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BLOCKED = 'blocked';

    public const CHANNELS = ['facebook_feed', 'instagram_feed', 'facebook_story', 'instagram_story'];

    protected $fillable = [
        'social_post_id', 'origin', 'product_id', 'owner_id', 'request_key', 'channel', 'account_id',
        'scheduled_for', 'status', 'phase', 'snapshot', 'caption', 'image_path', 'mime_type',
        'media_key', 'media_expires_at', 'container_id', 'media_id', 'external_id', 'permalink',
        'error', 'claimed_at', 'published_at',
    ];

    protected $hidden = ['media_key', 'image_path'];

    protected function casts(): array
    {
        return ['social_post_id' => 'integer', 'product_id' => 'integer', 'owner_id' => 'integer', 'snapshot' => 'array'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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

    protected function mediaExpiresAt(): Attribute
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
        return Attribute::make(
            get: static fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC'),
            set: static fn ($value) => $value === null ? null : ($value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC'))->utc()->format('Y-m-d H:i:s'),
        );
    }
}
