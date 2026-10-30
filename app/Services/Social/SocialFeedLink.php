<?php

namespace App\Services\Social;

use App\Models\SocialPublication;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Throwable;

class SocialFeedLink
{
    public function __construct(protected PublicationPreparer $preparer) {}

    public function enabled(): bool
    {
        return config('social.facebook_native_link', false) === true;
    }

    public function target(SocialPublication $publication): string
    {
        $base = $publication->snapshot['url'] ?? null;
        $parts = is_string($base) ? parse_url($base) : false;
        $origin = parse_url((string) config('app.url', URL::to('/')));
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! filter_var($base, FILTER_VALIDATE_URL)
            || strtolower($parts['host'] ?? '') !== strtolower($origin['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['fragment']) || isset($parts['query'])
            || ! preg_match('~/product/[^/]+\z~D', $parts['path'] ?? '')
            || strlen($base) > 1500 || preg_match('/[\x00-\x20\x7f]/', $base)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|20|7f)/i', $base)) {
            throw new DomainException('link_unavailable');
        }

        return $base.'?'.http_build_query([
            'social_publication' => $publication->getKey(), 'social_share' => $this->key($publication),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function imageUrl(SocialPublication $publication): string
    {
        return URL::route('social.feed-image', ['publication' => $publication->getKey(), 'key' => $this->key($publication)]);
    }

    /** This signature grants access only to one public product artwork, never account credentials. */
    public function key(SocialPublication $publication): string
    {
        $secret = config('app.key');
        if (! is_string($secret) || strlen($secret) < 16 || ! $publication->exists
            || ! is_string($publication->image_path) || $publication->image_path === '') {
            throw new DomainException('link_unavailable');
        }

        return hash_hmac('sha256', implode('|', ['social-feed-v1', $publication->getKey(),
            $publication->product_id, $publication->image_path]), $secret);
    }

    public function imagePath(SocialPublication $publication, string $key): ?string
    {
        try {
            if (! preg_match('/\A[a-f0-9]{64}\z/D', $key) || ! hash_equals($this->key($publication), $key)
                || $publication->channel !== 'facebook_feed' || $publication->account_id !== SocialSettings::PAGE_ID
                || ! in_array($publication->status, ['publishing', 'published', 'unknown'], true)) {
                return null;
            }
            $this->preparer->assertCurrent($publication);

            return $this->preparer->imagePath($publication);
        } catch (Throwable) {
            return null;
        }
    }

    public function metadata(int $productId, Request $request): array
    {
        $id = $request->query('social_publication');
        $key = $request->query('social_share');
        if (! is_string($id) || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) || ! is_string($key)) {
            return [];
        }
        try {
            $publication = SocialPublication::find($id);
            if ($publication === null || $publication->product_id !== $productId || ($path = $this->imagePath($publication, $key)) === null) {
                return [];
            }
            $size = @getimagesize($path);
            if (! is_array($size)) {
                return [];
            }

            return ['og_url' => $this->target($publication), 'image' => $this->imageUrl($publication),
                'image_width' => $size[0], 'image_height' => $size[1]];
        } catch (Throwable) {
            // An old or malformed share link must still open the normal, current product page.
            return [];
        }
    }
}
