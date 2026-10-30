<?php

declare(strict_types=1);

namespace SocialTests;

use App\Http\Controllers\SocialPublicationMediaController;
use App\Models\SocialPost;
use App\Models\SocialPublication;
use App\Services\Social\BusinessSuitePreparer;
use App\Services\Social\PublicationPreparer;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\ViewServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicationAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require SOCIAL_MULTICHANNEL_PAYLOAD.'/database/migrations/2026_10_29_120000_create_social_publications.php')->up();
        $this->app['translation.loader']->addPath(SOCIAL_MULTICHANNEL_PAYLOAD.'/lang');
        config(['view.paths' => [SOCIAL_TEST_SOURCE_ROOT.'/resources/views'], 'view.compiled' => $this->fixtureRoot.'/views']);
        $this->app->register(ViewServiceProvider::class);
        Route::get('/social-media/{publication}/{key}', SocialPublicationMediaController::class)->name('social.media');
        Route::get('/admin/social/bundles/{bundle}/{format}', fn () => 'fixture')->name('admin.social.bundle.asset');
        Route::getRoutes()->refreshNameLookups();
        $image = imagecreatetruecolor(500, 350);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 125, 40, 375, 310, imagecolorallocate($image, 80, 95, 110));
        imagepng($image, Storage::disk('public')->path('products/fixture.png'));
        imagedestroy($image);
    }

    public function test_manual_preparation_allows_an_undiscounted_item_below_500_without_touching_automatic_plans(): void
    {
        $product = $this->product(['price' => 49, 'old_price' => null]);
        $publication = $this->publication($product->id);
        $prepared = $this->app->make(PublicationPreparer::class)->prepare($publication);

        $this->assertSame(49, $prepared->snapshot['price']);
        $this->assertSame(0, $prepared->snapshot['old_price']);
        $this->assertSame('publishing', $prepared->status);
        $this->assertSame('preparing', $prepared->phase);
        $this->assertSame(0, SocialPost::count());
        $this->assertStringContainsString('49 ₾', $prepared->caption);
        $this->assertStringNotContainsString('ფასდაკლება', $prepared->caption);
    }

    public function test_automatic_preparation_keeps_the_500_and_real_discount_requirements(): void
    {
        $product = $this->product(['price' => 49, 'old_price' => null]);
        $publication = $this->publication($product->id, ['origin' => 'automatic']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('product_unavailable');
        $this->app->make(PublicationPreparer::class)->prepare($publication);
    }

    public function test_both_instagram_formats_are_real_jpegs_with_exact_dimensions(): void
    {
        $product = $this->product();
        $preparer = $this->app->make(PublicationPreparer::class);
        foreach (['instagram_feed' => 1350, 'instagram_story' => 1920] as $channel => $height) {
            $prepared = $preparer->prepare($this->publication($product->id, ['channel' => $channel]));
            $path = $preparer->imagePath($prepared);
            $size = getimagesize($path);
            $this->assertSame([1080, $height, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);
            $this->assertSame('image/jpeg', $prepared->mime_type);
        }
    }

    public function test_facebook_story_is_a_real_private_portrait_png(): void
    {
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication($this->product()->id, ['channel' => 'facebook_story']));
        $size = getimagesize($preparer->imagePath($prepared));

        $this->assertSame([1080, 1920, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]]);
        $this->assertSame('image/png', $prepared->mime_type);
    }

    public function test_meta_media_url_is_signed_unguessable_and_serves_only_the_prepared_image(): void
    {
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication($this->product()->id));
        $url = $preparer->mediaUrl($prepared);
        $request = Request::create($url);
        $response = (new SocialPublicationMediaController)($request, $prepared, $prepared->media_key, $preparer);

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $prepared->media_key);
        $this->assertTrue($request->hasValidSignature());
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('access_token', $url);
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('media_key', $prepared->toArray());
        $this->assertArrayNotHasKey('image_path', $prepared->toArray());
    }

    public function test_tampering_with_the_key_or_signature_is_rejected(): void
    {
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication($this->product()->id));
        $url = $preparer->mediaUrl($prepared);
        $this->expectException(HttpException::class);
        (new SocialPublicationMediaController)(Request::create($url), $prepared, str_repeat('a', 64), $preparer);
    }

    public function test_expired_media_urls_are_rejected(): void
    {
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication($this->product()->id));
        $url = $preparer->mediaUrl($prepared);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(7));
        Carbon::setTestNow(CarbonImmutable::getTestNow());

        $this->expectException(HttpException::class);
        (new SocialPublicationMediaController)(Request::create($url), $prepared, $prepared->media_key, $preparer);
    }

    public function test_price_changes_or_lost_stock_block_old_media(): void
    {
        $product = $this->product();
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication($product->id));
        $url = $preparer->mediaUrl($prepared);
        DB::table('products')->where('id', $product->id)->update(['stock' => 0]);

        $this->expectException(HttpException::class);
        (new SocialPublicationMediaController)(Request::create($url), $prepared, $prepared->media_key, $preparer);
    }

    public function test_private_image_paths_reject_traversal_and_another_publication_filename(): void
    {
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication($this->product()->id));
        $prepared->image_path = '../private-key.png';
        $this->assertNull($preparer->imagePath($prepared));
        $prepared->image_path = 'social-publications/999-aaaaaaaaaaaaaaaa.jpg';
        $this->assertNull($preparer->imagePath($prepared));
    }

    public function test_native_feed_link_metadata_does_not_change_authoritative_fact_validation(): void
    {
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication($this->product()->id));
        $snapshot = $prepared->snapshot;
        $snapshot['native_feed_link'] = 'https://iapi.invalid/product/verified-native-target';
        $prepared->forceFill(['snapshot' => $snapshot])->save();

        $preparer->assertCurrent($prepared->fresh());
        $this->assertSame($snapshot, $prepared->fresh()->snapshot);
    }

    #[DataProvider('changedNativeFacts')]
    public function test_native_metadata_does_not_hide_a_changed_price_stock_or_source_photo(string $changed, string $reason): void
    {
        $product = $this->product();
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication($product->id));
        $prepared->forceFill(['snapshot' => $prepared->snapshot + ['native_feed_link' => 'https://iapi.invalid/product/native']])->save();
        if ($changed === 'photo') {
            $image = imagecreatetruecolor(32, 32);
            imagefill($image, 0, 0, imagecolorallocate($image, 200, 20, 20));
            imagepng($image, Storage::disk('public')->path('products/fixture.png'));
            imagedestroy($image);
        } else {
            DB::table('products')->where('id', $product->id)->update($changed === 'price' ? ['price' => 900] : ['stock' => 0]);
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($reason);
        $preparer->assertCurrent($prepared->fresh());
    }

    public static function changedNativeFacts(): array
    {
        return [['price', 'publication_stale'], ['stock', 'product_unavailable'], ['photo', 'publication_stale']];
    }

    public function test_only_the_single_reserved_native_key_is_excluded_from_fact_comparison(): void
    {
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication($this->product()->id));
        $prepared->forceFill(['snapshot' => $prepared->snapshot + [
            'native_feed_link' => 'https://iapi.invalid/product/native', 'unexpected_metadata' => 'must not be ignored',
        ]])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('publication_stale');
        $preparer->assertCurrent($prepared->fresh());
    }

    public function test_suite_bundle_is_private_owner_bound_and_separate_from_all_publication_ledgers(): void
    {
        $service = $this->app->make(BusinessSuitePreparer::class);
        $bundle = $service->prepareProduct($this->product(['price' => 39, 'old_price' => null])->id, 1);
        $feed = $service->asset($bundle['id'], 1, 'feed');
        $story = $service->asset($bundle['id'], 1, 'story');

        $this->assertSame('image/png', $feed['mime']);
        $this->assertSame('image/jpeg', $story['mime']);
        $this->assertSame(0, SocialPost::count());
        $this->assertSame(0, SocialPublication::count());
        $this->assertArrayNotHasKey('path', $bundle);
        $this->assertStringNotContainsString($this->fixtureRoot, json_encode($bundle));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bundle_missing');
        $service->asset($bundle['id'], 2, 'feed');
    }

    public function test_suite_bundle_rejects_changed_product_facts(): void
    {
        $product = $this->product();
        $service = $this->app->make(BusinessSuitePreparer::class);
        $bundle = $service->prepareProduct($product->id, 1);
        DB::table('products')->where('id', $product->id)->update(['price' => 850]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bundle_stale');
        $service->asset($bundle['id'], 1, 'feed');
    }

    public function test_suite_bundle_does_not_export_an_already_published_automatic_post(): void
    {
        $post = $this->post($this->product(), ['status' => 'published']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('post_not_preparable');
        $this->app->make(BusinessSuitePreparer::class)->preparePost($post->id, 1);
    }

    private function publication(int $productId, array $attributes = []): SocialPublication
    {
        return SocialPublication::create(array_replace([
            'origin' => 'manual', 'product_id' => $productId, 'owner_id' => 1,
            'request_key' => bin2hex(random_bytes(32)), 'channel' => 'instagram_feed',
            'account_id' => '17841458783729265', 'scheduled_for' => now('UTC'),
            'status' => 'publishing', 'phase' => 'preparing', 'claimed_at' => now('UTC'),
        ], $attributes));
    }
}
