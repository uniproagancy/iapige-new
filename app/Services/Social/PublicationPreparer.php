<?php

namespace App\Services\Social;

use App\Models\Product;
use App\Models\SocialPublication;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Throwable;

class PublicationPreparer
{
    public function __construct(
        protected ProductSelector $selector,
        protected PostArtwork $artwork,
        protected SocialSettings $settings,
        protected PostPlanner $planner,
    ) {}

    public function prepare(SocialPublication $publication): SocialPublication
    {
        if (! in_array($publication->status, ['draft', 'ready', 'publishing'], true)
            || ! in_array($publication->channel, SocialPublication::CHANNELS, true)) {
            throw new DomainException('post_not_preparable');
        }
        $snapshot = $this->snapshot($publication);
        $values = $this->settings->values();
        $theme = $publication->post?->theme ?? $this->planner->themeFor(now('UTC'), $values['theme']);
        $format = str_ends_with($publication->channel, '_story') ? 'story' : 'feed';
        if ($publication->channel === 'facebook_feed' && config('social.facebook_native_link', false) === true) {
            $format = 'link';
        }
        $jpeg = str_starts_with($publication->channel, 'instagram_');
        $directory = storage_path('app/private/social-publications');
        File::ensureDirectoryExists($directory, 0700);
        $stem = $publication->id.'-'.bin2hex(random_bytes(8));
        $png = $directory.'/'.$stem.'.png';
        $relative = 'social-publications/'.$stem.($jpeg ? '.jpg' : '.png');
        $destination = storage_path('app/private/'.$relative);
        $render = array_replace($snapshot, [
            'image_path' => $this->selector->localImagePath($snapshot['image_path']),
            'old_price' => $snapshot['old_price'] > $snapshot['price'] ? $snapshot['old_price'] : null,
            'monthly_price' => $snapshot['price'] >= ProductSelector::MIN_PRICE && $snapshot['monthly_price'] > 0
                ? $snapshot['monthly_price'] : null,
        ]);
        try {
            $this->artwork->render($render, $theme, $png, $values['artwork'], $format);
            if ($jpeg) {
                $image = @imagecreatefrompng($png);
                if ($image === false) {
                    throw new DomainException('preparation_failed');
                }
                try {
                    if (! imagejpeg($image, $destination, 92)) {
                        throw new DomainException('preparation_failed');
                    }
                } finally {
                    imagedestroy($image);
                }
                File::delete($png);
            }
            chmod($destination, 0600);
            if ($this->snapshot($publication) !== $snapshot) {
                throw new DomainException('publication_stale');
            }
            $publication->forceFill([
                'snapshot' => $snapshot, 'caption' => $this->caption($publication, $snapshot),
                'image_path' => $relative, 'mime_type' => $jpeg ? 'image/jpeg' : 'image/png',
                'media_key' => bin2hex(random_bytes(32)),
                'media_expires_at' => CarbonImmutable::now('UTC')->addHours(6),
            ])->save();

            return $publication->refresh();
        } catch (Throwable $exception) {
            File::delete([$png, $destination]);
            throw $exception;
        }
    }

    public function assertCurrent(SocialPublication $publication): void
    {
        $expected = $publication->snapshot;
        if (! is_array($expected)) {
            throw new DomainException('publication_stale');
        }
        // The native Feed target is transport metadata, validated by SocialFeedLink.
        unset($expected['native_feed_link']);
        $current = $this->snapshot($publication);
        // JSON round-trips whole-number prices as integers.
        if ($current !== $expected && json_encode($current, JSON_THROW_ON_ERROR) !== json_encode($expected, JSON_THROW_ON_ERROR)) {
            throw new DomainException('publication_stale');
        }
    }

    public function imagePath(SocialPublication $publication): ?string
    {
        $relative = $publication->image_path;
        if (! is_string($relative)
            || ! preg_match('/\Asocial-publications\/([0-9]+)-[a-f0-9]{16}\.(png|jpg)\z/D', $relative, $parts)
            || (int) $parts[1] !== (int) $publication->id) {
            return null;
        }
        $root = realpath(storage_path('app/private/social-publications'));
        $path = storage_path('app/private/'.$relative);
        $resolved = realpath($path);
        if ($root === false || $resolved === false || is_link($path) || is_link(dirname($path))
            || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalized = str_replace('\\', '/', $resolved);
        if (DIRECTORY_SEPARATOR === '\\') {
            $prefix = strtolower($prefix);
            $normalized = strtolower($normalized);
        }
        $size = @getimagesize($resolved);
        $mime = $parts[2] === 'jpg' ? 'image/jpeg' : 'image/png';
        $height = str_ends_with($publication->channel, '_story') ? 1920 : 1350;

        $dimensionsValid = is_array($size) && (($size[0] === 1080 && $size[1] === $height)
            || ($publication->channel === 'facebook_feed' && $size[0] === PostArtwork::LINK_WIDTH && $size[1] === PostArtwork::LINK_HEIGHT));

        return str_starts_with($normalized, $prefix) && $dimensionsValid && ($size['mime'] ?? null) === $mime
            && $publication->mime_type === $mime && filesize($resolved) <= 8 * 1024 * 1024
                ? $resolved : null;
    }

    public function mediaUrl(SocialPublication $publication): string
    {
        if ($this->imagePath($publication) === null || ! is_string($publication->media_key)
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $publication->media_key)
            || $publication->media_expires_at === null || $publication->media_expires_at->isPast()) {
            throw new DomainException('media_unavailable');
        }

        return URL::temporarySignedRoute('social.media', $publication->media_expires_at, [
            'publication' => $publication->id, 'key' => $publication->media_key,
        ]);
    }

    protected function snapshot(SocialPublication $publication): array
    {
        $product = Product::withTranslation('ka')->with(['brand', 'images', 'livePromotions'])->find($publication->product_id);
        $snapshot = $product === null ? null : ($publication->origin === SocialPublication::ORIGIN_MANUAL
            ? $this->selector->manualSnapshot($product) : $this->selector->snapshot($product));
        if ($snapshot === null) {
            throw new DomainException('product_unavailable');
        }
        $path = $this->selector->localImagePath($snapshot['image_path']);
        if ($path === null) {
            throw new DomainException('product_unavailable');
        }
        $snapshot['source_image_sha256'] = hash_file('sha256', $path);

        return $snapshot;
    }

    protected function caption(SocialPublication $publication, array $snapshot): string
    {
        $url = $snapshot['url'].(str_contains($snapshot['url'], '?') ? '&' : '?').http_build_query([
            'utm_source' => str_starts_with($publication->channel, 'instagram_') ? 'instagram' : 'facebook',
            'utm_medium' => 'organic', 'utm_campaign' => 'iapi_'.$publication->origin,
            'utm_content' => 'publication-'.$publication->id,
        ]);
        $key = $snapshot['old_price'] > $snapshot['price'] && $snapshot['discount_percent'] > 0
            ? 'social.suite.caption_discount' : 'social.suite.caption_standard';
        $caption = __($key, [
            'title' => $snapshot['title'], 'price' => money($snapshot['price']), 'old_price' => money($snapshot['old_price']),
            'discount' => $snapshot['discount_percent'], 'monthly' => money($snapshot['monthly_price']),
            'months' => $snapshot['installment_months'], 'url' => $url,
        ], 'ka');
        if (! is_string($caption) || $caption === $key || trim($caption) === '') {
            throw new DomainException('preparation_failed');
        }

        return $caption;
    }
}
