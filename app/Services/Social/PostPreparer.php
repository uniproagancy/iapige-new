<?php

namespace App\Services\Social;

use App\Models\Product;
use App\Models\SocialPost;
use DomainException;
use Illuminate\Support\Facades\File;

class PostPreparer
{
    public function __construct(protected ProductSelector $selector, protected PostArtwork $artwork, protected ?SocialSettings $settings = null) {}

    public function prepare(SocialPost $post): SocialPost
    {
        if (! in_array($post->status, ['draft', 'ready', 'publishing'], true)) {
            throw new DomainException('post_not_preparable');
        }
        $product = Product::withTranslation('ka')->with(['images', 'livePromotions'])->find($post->product_id);
        $snapshot = $product ? $this->selector->snapshot($product) : null;
        if ($snapshot === null) {
            throw new DomainException('product_unavailable');
        }
        $photo = $this->selector->localImagePath($snapshot['image_path']);
        if ($photo === null) {
            throw new DomainException('product_unavailable');
        }

        $directory = storage_path('app/private/social-posts');
        File::ensureDirectoryExists($directory, 0700);
        $relative = 'social-posts/'.$post->id.'-'.bin2hex(random_bytes(8)).'.png';
        $destination = storage_path('app/private/'.$relative);
        $options = ($this->settings ?? app(SocialSettings::class))->values()['artwork'];
        $this->artwork->render(array_replace($snapshot, ['image_path' => $photo]), $post->theme, $destination, $options);
        chmod($destination, 0600);

        $url = $snapshot['url'];
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator.http_build_query(['utm_source' => 'facebook', 'utm_medium' => 'organic',
            'utm_campaign' => 'iapi_daily', 'utm_content' => 'social-'.$post->id]);
        $caption = __('social.caption_body', ['title' => $snapshot['title'], 'price' => money($snapshot['price']),
            'old_price' => money($snapshot['old_price']), 'discount' => $snapshot['discount_percent'],
            'monthly' => money($snapshot['monthly_price']), 'months' => $snapshot['installment_months'], 'url' => $url], 'ka');

        $post->forceFill(['snapshot' => $snapshot, 'caption' => $caption, 'image_path' => $relative,
            'status' => $post->status === 'publishing' ? 'publishing' : 'ready', 'error' => null])->save();

        return $post->refresh();
    }

    public function imagePath(SocialPost $post): ?string
    {
        $relative = $post->image_path;
        if (! is_string($relative) || ! preg_match('/\Asocial-posts\/([0-9]+)-[a-f0-9]{16}\.png\z/D', $relative, $parts)
            || (int) $parts[1] !== (int) $post->id) {
            return null;
        }
        $base = realpath(storage_path('app/private/social-posts'));
        $path = storage_path('app/private/'.$relative);
        if (! $base || is_link($path) || ! is_file($path)) {
            return null;
        }
        $resolved = realpath($path);

        return $resolved && str_starts_with($resolved, $base.DIRECTORY_SEPARATOR) ? $resolved : null;
    }
}
