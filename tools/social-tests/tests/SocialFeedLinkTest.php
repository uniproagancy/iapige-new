<?php

declare(strict_types=1);

namespace SocialTests;

use App\Http\Controllers\SocialFeedImageController;
use App\Models\SocialPublication;
use App\Services\Social\PublicationPreparer;
use App\Services\Social\SocialFeedLink;
use App\Services\Social\SocialSettings;
use App\Support\Seo;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\ViewServiceProvider;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class SocialFeedLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require SOCIAL_MULTICHANNEL_PAYLOAD.'/database/migrations/2026_10_29_120000_create_social_publications.php')->up();
        Route::get('/social-feed-image/{publication}/{key}', SocialFeedImageController::class)->name('social.feed-image');
        Route::getRoutes()->refreshNameLookups();
        $image = imagecreatetruecolor(1080, 1350);
        imagepng($image, $this->fixtureRoot.'/public/products/fixture.png');
        imagedestroy($image);
    }

    public function test_share_metadata_changes_only_the_og_object_and_image_with_a_clean_canonical(): void
    {
        $publication = $this->publication();
        $before = $publication->fresh()->getRawOriginal();
        $links = $this->links();
        $target = $links->target($publication);
        $metadata = $links->metadata($publication->product_id, Request::create($target));
        self::assertSame($target, $metadata['og_url']);
        self::assertSame('https://iapi.invalid/social-feed-image/'.$publication->id.'/'.$links->key($publication), $metadata['image']);
        self::assertSame(1080, $metadata['image_width']);
        self::assertSame(1350, $metadata['image_height']);
        $seo = Seo::meta(array_replace(['title' => 'Current product', 'canonical' => $publication->snapshot['url'],
            'price' => 800, 'schema' => [['@type' => 'Product', 'price' => 800]]], $metadata));
        self::assertSame($publication->snapshot['url'], $seo['canonical']);
        self::assertSame($target, $seo['og_url']);
        self::assertSame(800, $seo['price']);
        self::assertSame([['@type' => 'Product', 'price' => 800]], $seo['schema']);
        self::assertSame($before, $publication->fresh()->getRawOriginal(), 'A scrape must never mutate the ledger.');
    }

    public function test_signatures_are_specific_to_the_publication_product_and_immutable_artwork(): void
    {
        $publication = $this->publication();
        $links = $this->links();
        $original = $links->key($publication);
        $publication->image_path = 'social-publications/'.$publication->id.'-'.str_repeat('b', 16).'.png';
        self::assertNotSame($original, $links->key($publication));
        self::assertNull($links->imagePath($publication, $original));
        $publication->product_id++;
        self::assertNotSame($original, $links->key($publication));
    }

    #[DataProvider('invalidPublications')]
    public function test_unrelated_or_unclaimed_publications_cannot_override_product_metadata(array $attributes): void
    {
        $publication = $this->publication($attributes);
        $links = $this->links();
        self::assertSame([], $links->metadata($publication->product_id, Request::create($links->target($publication))));
    }

    public static function invalidPublications(): array
    {
        return array_map(fn ($attributes) => [$attributes], [['account_id' => '999'], ['channel' => 'instagram_feed'], ['channel' => 'facebook_story'],
            ['status' => 'draft'], ['status' => 'ready'], ['status' => 'failed']]);
    }

    public function test_wrong_product_wrong_signature_and_malformed_query_fall_back_without_current_checks(): void
    {
        $publication = $this->publication();
        $preparer = Mockery::mock(PublicationPreparer::class);
        $preparer->shouldNotReceive('assertCurrent');
        $links = new SocialFeedLink($preparer);
        self::assertSame([], $links->metadata($publication->product_id + 1, Request::create($links->target($publication))));
        self::assertSame([], $links->metadata($publication->product_id, Request::create($publication->snapshot['url'], 'GET',
            ['social_publication' => (string) $publication->id, 'social_share' => str_repeat('0', 64)])));
        self::assertSame([], $links->metadata($publication->product_id, Request::create($publication->snapshot['url'], 'GET',
            ['social_publication' => [$publication->id], 'social_share' => ['key']])));
        self::assertSame([], $links->metadata($publication->product_id, Request::create($publication->snapshot['url'])));
    }

    public function test_changed_current_price_or_stock_falls_back_and_denies_the_artwork(): void
    {
        $publication = $this->publication();
        $preparer = Mockery::mock(PublicationPreparer::class);
        $preparer->shouldReceive('assertCurrent')->twice()->andThrow(new DomainException('publication_stale'));
        $preparer->shouldNotReceive('imagePath');
        $links = new SocialFeedLink($preparer);
        self::assertSame([], $links->metadata($publication->product_id, Request::create($links->target($publication))));
        self::assertNull($links->imagePath($publication, $links->key($publication)));
    }

    public function test_share_artwork_does_not_expire_with_the_separate_six_hour_ingestion_link(): void
    {
        $publication = $this->publication(['media_expires_at' => CarbonImmutable::now('UTC')->subDay()]);
        $links = $this->links();
        self::assertNotEmpty($links->metadata($publication->product_id, Request::create($links->target($publication))));
        self::assertNotNull($links->imagePath($publication, $links->key($publication)));
    }

    #[DataProvider('unsafeUrls')]
    public function test_a_share_target_cannot_change_origin_or_carry_arbitrary_query_or_credentials(string $url): void
    {
        $publication = $this->publication(['snapshot' => ['url' => $url]]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('link_unavailable');
        $this->links()->target($publication);
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], ['https://evil.invalid/product/item', 'http://iapi.invalid/product/item',
            'https://owner@iapi.invalid/product/item', 'https://iapi.invalid:444/product/item',
            'https://iapi.invalid/product/item?redirect=https://evil.invalid', 'https://iapi.invalid/product/item#fragment',
            'https://iapi.invalid/product/%0Aitem', 'javascript:alert(1)', 'https://iapi.invalid/admin']);
    }

    public function test_native_publishing_is_disabled_unless_explicitly_configured_true(): void
    {
        $links = $this->links();
        self::assertFalse($links->enabled());
        config(['social.facebook_native_link' => 'false']);
        self::assertFalse($links->enabled());
        config(['social.facebook_native_link' => true]);
        self::assertTrue($links->enabled());
        $seo = Seo::meta(['canonical' => 'https://iapi.invalid/product/ordinary']);
        self::assertSame($seo['canonical'], $seo['og_url']);
        self::assertSame(1200, $seo['image_width']);
        self::assertSame(630, $seo['image_height']);
    }

    public function test_the_public_image_endpoint_serves_only_the_validated_raster_without_caching_or_credentials(): void
    {
        config(['view.paths' => [SOCIAL_TEST_SOURCE_ROOT.'/resources/views'], 'view.compiled' => $this->fixtureRoot.'/views']);
        $this->app->register(ViewServiceProvider::class);
        $publication = $this->publication();
        $links = $this->links();
        $response = (new SocialFeedImageController)($publication, $links->key($publication), $links);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(realpath($this->fixtureRoot.'/public/products/fixture.png'), $response->getFile()->getRealPath());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringNotContainsString('access_token', $links->imageUrl($publication));
        self::assertStringNotContainsString('social-publications', $links->imageUrl($publication));
    }

    private function publication(array $attributes = []): SocialPublication
    {
        $product = $this->product();
        $publication = SocialPublication::create(array_replace(['origin' => 'manual', 'product_id' => $product->id,
            'request_key' => bin2hex(random_bytes(32)), 'channel' => 'facebook_feed', 'account_id' => SocialSettings::PAGE_ID,
            'status' => 'publishing', 'phase' => 'publishing', 'scheduled_for' => CarbonImmutable::now('UTC'),
            'snapshot' => ['url' => 'https://iapi.invalid/ka/product/product-'.$product->id], 'mime_type' => 'image/png'], $attributes));
        $publication->forceFill(['image_path' => 'social-publications/'.$publication->id.'-'.str_repeat('a', 16).'.png'])->save();

        return $publication;
    }

    private function links(): SocialFeedLink
    {
        $preparer = Mockery::mock(PublicationPreparer::class);
        $preparer->shouldReceive('assertCurrent')->zeroOrMoreTimes();
        $preparer->shouldReceive('imagePath')->zeroOrMoreTimes()->andReturn($this->fixtureRoot.'/public/products/fixture.png');

        return new SocialFeedLink($preparer);
    }
}
