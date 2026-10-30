<?php

declare(strict_types=1);

namespace SocialTests;

use App\Http\Controllers\SocialFeedImageController;
use App\Models\SocialPublication;
use App\Services\Social\PublicationPreparer;
use App\Services\Social\SocialFeedLink;
use App\Services\Social\SocialSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

final class FacebookLinkFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require SOCIAL_MULTICHANNEL_PAYLOAD.'/database/migrations/2026_10_29_120000_create_social_publications.php')->up();
        $this->app['translation.loader']->addPath(SOCIAL_MULTICHANNEL_PAYLOAD.'/lang');
        Route::get('/social-feed-image/{publication}/{key}', SocialFeedImageController::class)->name('social.feed-image');
        Route::getRoutes()->refreshNameLookups();
        $image = imagecreatetruecolor(400, 500);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 70, 30, 330, 470, imagecolorallocate($image, 50, 80, 95));
        imagepng($image, Storage::disk('public')->path('products/fixture.png'));
        imagedestroy($image);
    }

    #[DataProvider('channelFormats')]
    public function test_native_landscape_is_used_only_for_facebook_feed(bool $native, string $channel, array $expected): void
    {
        config(['social.facebook_native_link' => $native]);
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication($channel));
        $path = $preparer->imagePath($prepared);

        self::assertNotNull($path);
        self::assertSame($expected, array_slice(getimagesize($path), 0, 3));
        $preparer->assertCurrent($prepared);
        self::assertSame('publishing', $prepared->status);
    }

    public static function channelFormats(): array
    {
        return [
            'native Facebook card' => [true, 'facebook_feed', [1200, 630, IMAGETYPE_PNG]],
            'legacy Facebook photo' => [false, 'facebook_feed', [1080, 1350, IMAGETYPE_PNG]],
            'Instagram feed unchanged' => [true, 'instagram_feed', [1080, 1350, IMAGETYPE_JPEG]],
            'Facebook Story unchanged' => [true, 'facebook_story', [1080, 1920, IMAGETYPE_PNG]],
            'Instagram Story unchanged' => [true, 'instagram_story', [1080, 1920, IMAGETYPE_JPEG]],
        ];
    }

    public function test_native_artwork_and_og_dimensions_survive_disabling_native_mode_after_publication(): void
    {
        config(['social.facebook_native_link' => true]);
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication('facebook_feed'));
        $prepared->forceFill(['status' => 'published'])->save();
        config(['social.facebook_native_link' => false]);
        $links = new SocialFeedLink($preparer);
        $target = $links->target($prepared);
        $metadata = $links->metadata($prepared->product_id, Request::create($target));

        self::assertNotNull($preparer->imagePath($prepared));
        self::assertSame([1200, 630], [$metadata['image_width'], $metadata['image_height']]);
        self::assertSame($target, $metadata['og_url']);
        self::assertSame($links->imageUrl($prepared), $metadata['image']);
    }

    public function test_existing_portrait_facebook_artwork_remains_valid_after_native_mode_is_enabled(): void
    {
        config(['social.facebook_native_link' => false]);
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication('facebook_feed'));
        $prepared->forceFill(['status' => 'published'])->save();
        config(['social.facebook_native_link' => true]);
        $links = new SocialFeedLink($preparer);
        $metadata = $links->metadata($prepared->product_id, Request::create($links->target($prepared)));

        self::assertNotNull($preparer->imagePath($prepared));
        self::assertSame([1080, 1350], [$metadata['image_width'], $metadata['image_height']]);
    }

    public function test_a_landscape_facebook_file_cannot_be_reused_as_an_instagram_feed_or_story_asset(): void
    {
        config(['social.facebook_native_link' => true]);
        $preparer = $this->app->make(PublicationPreparer::class);
        $prepared = $preparer->prepare($this->publication('facebook_feed'));

        foreach (['instagram_feed', 'facebook_story', 'instagram_story'] as $channel) {
            $prepared->channel = $channel;
            self::assertNull($preparer->imagePath($prepared));
        }
    }

    private function publication(string $channel): SocialPublication
    {
        return SocialPublication::create([
            'origin' => 'manual', 'product_id' => $this->product()->id, 'owner_id' => 1,
            'request_key' => bin2hex(random_bytes(32)), 'channel' => $channel,
            'account_id' => str_starts_with($channel, 'instagram_') ? '17841458783729265' : SocialSettings::PAGE_ID,
            'scheduled_for' => now('UTC'), 'status' => 'publishing', 'phase' => 'preparing', 'claimed_at' => now('UTC'),
        ]);
    }
}
