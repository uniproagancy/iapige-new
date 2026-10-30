<?php

namespace App\Services\Social;

use App\Models\Product;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

class BusinessSuitePreparer
{
    public const LIFETIME_HOURS = 12;

    public function __construct(
        protected ProductSelector $selector,
        protected PostArtwork $artwork,
        protected SocialSettings $settings,
        protected PostPlanner $planner,
    ) {}

    public function prepareProduct(int $productId, int $ownerId): array
    {
        return $this->prepare($productId, $ownerId);
    }

    public function preparePost(int $postId, int $ownerId): array
    {
        $post = $this->futurePost($postId);

        return $this->prepare((int) $post->product_id, $ownerId, $post);
    }

    /** Returns a local asset only after owner, expiry and current source facts are checked. */
    public function asset(string $id, int $ownerId, string $format): array
    {
        if ($ownerId < 1 || ! preg_match('/\A[a-f0-9]{64}\z/D', $id) || ! in_array($format, ['feed', 'story'], true)) {
            throw new DomainException('bundle_missing');
        }

        $bundle = Cache::get($this->cacheKey($id));
        if (! is_array($bundle) || ($bundle['owner_id'] ?? null) !== $ownerId || ($bundle['id'] ?? null) !== $id) {
            throw new DomainException('bundle_missing');
        }
        if (($bundle['expires_at'] ?? 0) <= now('UTC')->timestamp) {
            throw new DomainException('bundle_expired');
        }
        if ($bundle['source_post_id'] !== null) {
            $this->futurePost((int) $bundle['source_post_id']);
        }

        $snapshot = $this->snapshot((int) $bundle['snapshot']['product_id']);
        if ($this->fingerprint($snapshot) !== $bundle['fingerprint']) {
            throw new DomainException('bundle_stale');
        }

        $extension = $format === 'feed' ? 'png' : 'jpg';
        $directory = $this->directory($ownerId, $id);
        $path = $directory.'/'.$format.'.'.$extension;
        $private = realpath(storage_path('app/private'));
        $resolved = realpath($path);
        if ($private === false || $resolved === false || ! is_file($resolved) || ! is_readable($resolved)
            || ! $this->within($resolved, $private) || $this->hasSymlink($path, storage_path('app/private'))) {
            throw new DomainException('bundle_missing');
        }
        $size = @getimagesize($resolved);
        $expectedType = $format === 'feed' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        if (! is_array($size) || $size[0] !== 1080 || $size[1] !== ($format === 'feed' ? 1350 : 1920)
            || $size[2] !== $expectedType || filesize($resolved) > 8 * 1024 * 1024) {
            throw new DomainException('bundle_missing');
        }

        return [
            'path' => $resolved,
            'mime' => $format === 'feed' ? 'image/png' : 'image/jpeg',
            'filename' => 'iapi-'.$snapshot['product_id'].'-'.$format.'.'.$extension,
        ];
    }

    protected function prepare(int $productId, int $ownerId, ?SocialPost $post = null): array
    {
        if ($ownerId < 1) {
            throw new DomainException('bundle_missing');
        }
        $snapshot = $this->snapshot($productId);
        $fingerprint = $this->fingerprint($snapshot);
        $values = $this->settings->values();
        $theme = $post?->theme ?? $this->planner->themeFor(now('UTC'), $values['theme']);
        $id = bin2hex(random_bytes(32));
        $directory = $this->directory($ownerId, $id);
        File::ensureDirectoryExists($directory, 0700);
        chmod($directory, 0700);
        $photo = $this->selector->localImagePath($snapshot['image_path']);
        $render = array_replace($snapshot, [
            'image_path' => $photo,
            'old_price' => $snapshot['old_price'] > $snapshot['price'] ? $snapshot['old_price'] : null,
            // Small manual items must not imply zero-lari or ineligible installments.
            'monthly_price' => $snapshot['price'] >= ProductSelector::MIN_PRICE && $snapshot['monthly_price'] > 0
                ? $snapshot['monthly_price'] : null,
        ]);

        try {
            $this->artwork->render($render, $theme, $directory.'/feed.png', $values['artwork'], 'feed');
            $this->artwork->render($render, $theme, $directory.'/story.png', $values['artwork'], 'story');
            $story = @imagecreatefrompng($directory.'/story.png');
            if ($story === false) {
                throw new DomainException('preparation_failed');
            }
            try {
                if (! imagejpeg($story, $directory.'/story.jpg', 92)) {
                    throw new DomainException('preparation_failed');
                }
            } finally {
                imagedestroy($story);
            }
            File::delete($directory.'/story.png');
            chmod($directory.'/feed.png', 0600);
            chmod($directory.'/story.jpg', 0600);
            // The catalogue may change while two formats are rendering.
            if ($this->fingerprint($this->snapshot($productId)) !== $fingerprint) {
                throw new DomainException('bundle_stale');
            }

            $expires = CarbonImmutable::now('UTC')->addHours(self::LIFETIME_HOURS);
            $caption = $this->caption($snapshot, $id);
            Cache::put($this->cacheKey($id), [
                'id' => $id, 'owner_id' => $ownerId, 'expires_at' => $expires->timestamp,
                'source_post_id' => $post?->id, 'snapshot' => $snapshot, 'fingerprint' => $fingerprint,
            ], $expires);
            $assets = [];
            foreach (['feed' => [1350, 'image/png'], 'story' => [1920, 'image/jpeg']] as $format => [$height, $mime]) {
                $parameters = ['bundle' => $id, 'format' => $format];
                $assets[$format] = [
                    'preview_url' => route('admin.social.bundle.asset', $parameters),
                    'download_url' => route('admin.social.bundle.asset', $parameters + ['download' => 1]),
                    'width' => 1080, 'height' => $height, 'mime' => $mime,
                ];
            }

            return [
                'id' => $id, 'title' => $snapshot['title'], 'caption' => $caption,
                'product_url' => $snapshot['url'], 'expires_at' => $expires->toIso8601String(),
                'source_post_id' => $post?->id, 'assets' => $assets,
            ];
        } catch (Throwable $exception) {
            Cache::forget($this->cacheKey($id));
            $resolved = realpath($directory);
            $private = realpath(storage_path('app/private'));
            if ($resolved !== false && $private !== false && $this->within($resolved, $private)
                && ! $this->hasSymlink($directory, storage_path('app/private'))) {
                File::deleteDirectory($resolved);
            }
            throw $exception;
        }
    }

    protected function snapshot(int $productId): array
    {
        $product = $productId > 0
            ? Product::withTranslation('ka')->with(['brand', 'images', 'livePromotions'])->find($productId)
            : null;
        $snapshot = $product ? $this->selector->manualSnapshot($product) : null;
        if ($snapshot === null) {
            throw new DomainException('product_unavailable');
        }

        return $snapshot;
    }

    protected function fingerprint(array $snapshot): string
    {
        $photo = $this->selector->localImagePath($snapshot['image_path']);
        if ($photo === null) {
            throw new DomainException('product_unavailable');
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR).'|'.hash_file('sha256', $photo));
    }

    protected function futurePost(int $postId): SocialPost
    {
        $post = $postId > 0 ? SocialPost::find($postId) : null;
        if ($post === null || $post->page_id !== SocialSettings::PAGE_ID
            || ! in_array($post->status, [SocialPost::STATUS_DRAFT, SocialPost::STATUS_READY], true)
            || $post->scheduled_date !== now(ProductSelector::TIMEZONE)->toDateString()
            || ! $post->scheduled_for->greaterThan(now('UTC'))) {
            throw new DomainException('post_not_preparable');
        }

        return $post;
    }

    protected function caption(array $snapshot, string $id): string
    {
        $url = $snapshot['url'].(str_contains($snapshot['url'], '?') ? '&' : '?').http_build_query([
            'utm_source' => 'meta', 'utm_medium' => 'organic', 'utm_campaign' => 'iapi_suite',
            'utm_content' => 'manual-'.$id,
        ]);
        $key = $snapshot['old_price'] > $snapshot['price'] && $snapshot['discount_percent'] > 0
            ? 'social.suite.caption_discount' : 'social.suite.caption_standard';
        $caption = __($key, [
            'title' => $snapshot['title'], 'price' => money($snapshot['price']),
            'old_price' => money($snapshot['old_price']), 'discount' => $snapshot['discount_percent'],
            'monthly' => money($snapshot['monthly_price']), 'months' => $snapshot['installment_months'], 'url' => $url,
        ], 'ka');
        if (! is_string($caption) || $caption === $key || trim($caption) === '') {
            throw new DomainException('preparation_failed');
        }

        return $caption;
    }

    protected function directory(int $ownerId, string $id): string
    {
        return storage_path('app/private/business-suite/'.$ownerId.'/'.$id);
    }

    protected function cacheKey(string $id): string
    {
        return 'social.suite.bundle.'.$id;
    }

    protected function within(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';

        return DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($path), strtolower($root))
            : str_starts_with($path, $root);
    }

    protected function hasSymlink(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        while (strlen($path) > strlen($root)) {
            if (is_link($path)) {
                return true;
            }
            $path = str_replace('\\', '/', dirname($path));
        }

        return is_link($root);
    }
}
