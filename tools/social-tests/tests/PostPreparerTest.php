<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\SocialPost;
use App\Services\Social\PostArtwork;
use App\Services\Social\PostPlanner;
use App\Services\Social\PostPreparer;
use App\Services\Social\ProductSelector;
use App\Services\Social\SocialSettings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

class PostPreparerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('gd') || ! function_exists('imagettftext')) {
            $this->markTestSkipped('The real artwork integration requires GD with FreeType.');
        }
        $image = imagecreatetruecolor(500, 350);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 125, 40, 375, 310, imagecolorallocate($image, 80, 95, 110));
        imagepng($image, Storage::disk('public')->path('products/fixture.png'));
        imagedestroy($image);
    }

    public function test_preparation_renders_real_private_artwork_and_a_georgian_caption_from_live_facts(): void
    {
        $this->app->setLocale('en');
        $product = $this->product(['price' => 800, 'old_price' => 1000]);
        $post = $this->post($product, ['status' => SocialPost::STATUS_DRAFT, 'snapshot' => ['price' => 111, 'title' => 'Stale draft']]);
        $preparer = new PostPreparer(new ProductSelector, new PostArtwork);
        $prepared = $preparer->prepare($post);
        $path = $preparer->imagePath($prepared);
        $info = getimagesize($path);

        $this->assertSame(SocialPost::STATUS_READY, $prepared->status);
        $this->assertSame(800.0, (float) $prepared->snapshot['price']);
        $this->assertSame('პროდუქტი '.$product->id, $prepared->snapshot['title']);
        $this->assertSame([1080, 1350, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
        $this->assertGreaterThan(20000, filesize($path));
        $this->assertStringStartsWith(realpath(storage_path('app/private/social-posts')).DIRECTORY_SEPARATOR, $path);
        $this->assertStringNotContainsString('public', str_replace($this->fixtureRoot, '', $path));
        $this->assertMatchesRegularExpression('/[\x{10A0}-\x{10FF}]/u', $prepared->caption);
        $this->assertStringContainsString('პროდუქტი '.$product->id, $prepared->caption);
        $this->assertStringContainsString('800 ₾', $prepared->caption);
        $this->assertStringContainsString('1 000 ₾', $prepared->caption);
        $this->assertStringContainsString('ფასდაკლება 20%', $prepared->caption);
        $this->assertStringContainsString('33 ₾', $prepared->caption);
        $this->assertStringContainsString('24 თვე', $prepared->caption);
        $this->assertStringContainsString('utm_source=facebook', $prepared->caption);
        $this->assertStringContainsString('utm_content=social-'.$post->id, $prepared->caption);
        $this->assertStringNotContainsString('social.caption_body', $prepared->caption);
        $this->assertStringNotContainsString('Stale draft', $prepared->caption);
        $this->assertNotSame(__('social.caption'), $prepared->caption);
        $this->assertSame('en', $this->app->getLocale());
        $this->assertNull($prepared->error);
    }

    #[DataProvider('unavailableProducts')]
    public function test_unavailable_product_changes_are_rejected_before_creating_artwork(array $changes): void
    {
        $product = $this->product();
        $post = $this->post($product, ['status' => SocialPost::STATUS_DRAFT]);
        DB::table('products')->where('id', $product->id)->update($changes);
        try {
            (new PostPreparer(new ProductSelector, new PostArtwork))->prepare($post);
            $this->fail('Changed product facts must be checked again.');
        } catch (DomainException $exception) {
            $this->assertSame('product_unavailable', $exception->getMessage());
        }

        $this->assertSame(SocialPost::STATUS_DRAFT, $post->fresh()->status);
        $this->assertNull($post->fresh()->image_path);
        $this->assertSame([], glob(storage_path('app/private/social-posts/*.png')) ?: []);
    }

    public static function unavailableProducts(): iterable
    {
        yield 'out of stock' => [['stock' => 0]];
        yield 'preorder' => [['is_preorder' => true]];
        yield 'unpublished status' => [['status' => 'draft']];
        yield 'future publish date' => [['published_at' => '2027-01-01 00:00:00']];
        yield 'below final price threshold' => [['price' => 499]];
        yield 'discount ended' => [['old_price' => 800]];
        yield 'product removed' => [['deleted_at' => '2026-10-29 04:00:00']];
    }

    public function test_changed_live_promotion_is_refreshed_and_a_drop_below_500_stops_preparation(): void
    {
        $product = $this->product(['price' => 1000, 'old_price' => 1200]);
        $this->promotion($product, ['promo_price' => 800]);
        $post = $this->post($product, ['status' => SocialPost::STATUS_DRAFT, 'snapshot' => ['price' => 800]]);
        DB::table('promotion_product')->where('product_id', $product->id)->update(['promo_price' => 550]);
        $preparer = new PostPreparer(new ProductSelector, new PostArtwork);
        $prepared = $preparer->prepare($post);

        $this->assertSame(550.0, (float) $prepared->snapshot['price']);
        $this->assertSame(54, $prepared->snapshot['discount_percent']);
        $this->assertStringContainsString('550 ₾', $prepared->caption);
        $this->assertStringContainsString('54%', $prepared->caption);
        DB::table('promotion_product')->where('product_id', $product->id)->update(['promo_price' => 499]);
        try {
            $preparer->prepare($prepared);
            $this->fail('A promotion below 500 GEL must not reuse old artwork facts.');
        } catch (DomainException $exception) {
            $this->assertSame('product_unavailable', $exception->getMessage());
        }
        $this->assertSame(550.0, (float) $prepared->fresh()->snapshot['price']);
    }

    public function test_expired_promotion_does_not_reuse_a_draft_discount(): void
    {
        $product = $this->product(['price' => 1000, 'old_price' => null]);
        $this->promotion($product, ['promo_price' => 600]);
        $post = $this->post($product, ['status' => SocialPost::STATUS_DRAFT, 'snapshot' => ['price' => 600, 'old_price' => 1000]]);
        DB::table('promotions')->update(['ends_at' => CarbonImmutable::now()->subMinute()->utc()]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('product_unavailable');
        (new PostPreparer(new ProductSelector, new PostArtwork))->prepare($post);
    }

    public function test_exact_500_gel_final_price_is_allowed_and_publishing_claim_is_preserved(): void
    {
        $product = $this->product(['price' => 600, 'old_price' => null]);
        $this->promotion($product, ['promo_price' => 500]);
        $post = $this->post($product, ['status' => SocialPost::STATUS_PUBLISHING]);
        $prepared = (new PostPreparer(new ProductSelector, new PostArtwork))->prepare($post);

        $this->assertSame(SocialPost::STATUS_PUBLISHING, $prepared->status);
        $this->assertSame(500.0, (float) $prepared->snapshot['price']);
        $this->assertStringContainsString('500 ₾', $prepared->caption);
        $this->assertSame(17, $prepared->snapshot['discount_percent']);
    }

    public function test_removed_local_product_photo_stops_preparation(): void
    {
        $product = $this->product();
        $post = $this->post($product, ['status' => SocialPost::STATUS_DRAFT]);
        Storage::disk('public')->delete('products/fixture.png');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('product_unavailable');
        (new PostPreparer(new ProductSelector, new PostArtwork))->prepare($post);
    }

    public function test_saved_artwork_options_change_a_ready_preview_and_reset_to_default_without_changing_product_facts(): void
    {
        $product = $this->product();
        $post = $this->post($product, ['status' => SocialPost::STATUS_DRAFT]);
        $preparer = new PostPreparer(new ProductSelector, new PostArtwork);
        $prepared = $preparer->prepare($post);
        $originalPath = $preparer->imagePath($prepared);
        $originalHash = hash_file('sha256', $originalPath);
        $originalSnapshot = $prepared->snapshot;
        $originalCaption = $prepared->caption;
        $settings = new SocialSettings;
        $base = ['enabled' => false, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS];
        $settings->save($base + ['artwork' => [
            'accent_color' => '#2468AB', 'headline' => 'შენი ახალი ტექნიკა',
            'cta' => 'შეარჩიე ახლავე', 'tagline' => 'ტექნიკა შენთვის',
        ]]);
        $custom = $preparer->prepare($prepared);
        $customPath = $preparer->imagePath($custom);

        $this->assertNotSame($originalPath, $customPath);
        $this->assertNotSame($originalHash, hash_file('sha256', $customPath));
        $this->assertSame($originalSnapshot, $custom->snapshot);
        $this->assertSame($originalCaption, $custom->caption);
        $this->assertSame(SocialPost::STATUS_READY, $custom->status);
        $this->assertNull($custom->published_at);

        $settings->save($base + ['artwork' => ['accent_color' => '', 'headline' => '', 'cta' => '', 'tagline' => '']]);
        $reset = $preparer->prepare($custom);
        $this->assertSame($originalHash, hash_file('sha256', $preparer->imagePath($reset)));
        $this->assertSame($originalSnapshot, $reset->snapshot);
        $this->assertSame($originalCaption, $reset->caption);
    }

    public function test_private_artwork_lookup_rejects_remote_absolute_traversal_and_missing_paths(): void
    {
        $product = $this->product();
        $post = $this->post($product, ['status' => SocialPost::STATUS_DRAFT]);
        $preparer = new PostPreparer(new ProductSelector, new PostArtwork);
        $prepared = $preparer->prepare($post);
        $this->assertNotNull($preparer->imagePath($prepared));

        foreach ([
            null, 'https://external.invalid/art.png', '../public/products/fixture.png',
            'social-posts/../../products/fixture.png', storage_path('app/private/'.$prepared->image_path),
            'products/fixture.png', 'social-posts/1-not-a-valid-hash.png',
            'social-posts/999-aaaaaaaaaaaaaaaa.png',
        ] as $path) {
            $prepared->image_path = $path;
            $this->assertNull($preparer->imagePath($prepared));
        }
    }

    public function test_published_or_unknown_posts_cannot_be_prepared_again(): void
    {
        $product = $this->product();
        $post = $this->post($product, ['status' => SocialPost::STATUS_UNKNOWN]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('post_not_preparable');
        (new PostPreparer(new ProductSelector, new PostArtwork))->prepare($post);
    }
}
