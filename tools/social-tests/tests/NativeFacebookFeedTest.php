<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\Setting;
use App\Models\SocialPost;
use App\Models\SocialPublication;
use App\Services\Social\PagePublisher;
use App\Services\Social\PostArtwork;
use App\Services\Social\PostPlanner;
use App\Services\Social\PostPreparer;
use App\Services\Social\ProductSelector;
use App\Services\Social\PublicationPreparer;
use App\Services\Social\SocialFeedLink;
use App\Services\Social\SocialSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as ServerRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class NativeFacebookFeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['translation.loader']->addPath(SOCIAL_MULTICHANNEL_PAYLOAD.'/lang');
        Setting::forgetCache();
        config(['social.page_access_token' => 'fixture-page-token', 'social.facebook_native_link' => true]);
        (require SOCIAL_MULTICHANNEL_PAYLOAD.'/database/migrations/2026_10_29_120000_create_social_publications.php')->up();
    }

    public function test_manual_native_feed_uses_the_real_product_share_link_and_exact_shop_now_proof(): void
    {
        $publication = $this->manual();
        $this->meta();
        $publisher = new PagePublisher(new SocialSettings, Mockery::mock(PostPreparer::class), $this->preparer());
        self::assertTrue($publisher->publishManual($publication));
        $publication->refresh();
        self::assertSame('published', $publication->status);
        self::assertNull($publication->media_id, 'A link post ID must not be misrepresented as a photo ID.');
        self::assertSame(SocialSettings::PAGE_ID.'_123', $publication->external_id);
        $target = $publication->snapshot['native_feed_link'];
        self::assertStringStartsWith('https://iapi.invalid/ka/product/product-'.$publication->product_id.'?', $target);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/'.SocialSettings::PAGE_ID.'/feed')
            && $request['link'] === $target && $request['message'] === 'Fresh factual caption'
            && json_decode($request['call_to_action'], true) === ['type' => 'SHOP_NOW', 'value' => ['link' => $target]]
            && $request['published'] === 'true');
        self::assertSame(0, SocialPost::count());
        self::assertFalse($publisher->publishManual($publication));
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/photos'));
    }

    public function test_automatic_native_feed_keeps_the_legacy_claim_and_one_publication_mirror_with_one_write(): void
    {
        $this->enable();
        $product = $this->product();
        $post = $this->post($product, ['status' => 'draft', 'scheduled_date' => '2026-10-29',
            'scheduled_for' => CarbonImmutable::now('UTC')->subMinute()]);
        $legacy = Mockery::mock(PostPreparer::class);
        $legacy->shouldReceive('prepare')->once()->andReturnUsing(fn (SocialPost $claimed) => $claimed);
        $this->meta();
        $publisher = new PagePublisher(new SocialSettings, $legacy, $this->preparer());
        self::assertSame(1, $publisher->publishDue());
        self::assertSame(0, $publisher->publishDue());
        $mirror = SocialPublication::sole();
        self::assertSame('automatic', $mirror->origin);
        self::assertSame($post->id, $mirror->social_post_id);
        self::assertSame(hash('sha256', 'automatic|'.$post->id), $mirror->request_key);
        self::assertSame('published', $post->fresh()->status);
        self::assertSame('published', $mirror->status);
        self::assertSame($post->fresh()->post_id, $mirror->external_id);
        self::assertSame($post->fresh()->snapshot, $mirror->snapshot);
        self::assertArrayHasKey('source_image_sha256', $post->fresh()->snapshot);
        Http::assertSentCount(4); // Two identity reads, one write and one post verification.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/photos'));
    }

    public function test_a_wrong_cta_remains_unknown_and_get_only_recovery_keeps_native_proof_after_opt_out(): void
    {
        $publication = $this->manual();
        $this->meta(wrongCta: true);
        $publisher = new PagePublisher(new SocialSettings, Mockery::mock(PostPreparer::class), $this->preparer());
        self::assertFalse($publisher->publishManual($publication));
        self::assertSame('unknown', $publication->fresh()->status);
        self::assertSame(SocialSettings::PAGE_ID.'_123', $publication->fresh()->external_id);
        config(['social.facebook_native_link' => false]);
        $target = $publication->fresh()->snapshot['native_feed_link'];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI'])
            ->push($this->proof($target));
        self::assertSame(1, $publisher->reconcileManualUnknown());
        self::assertSame('published', $publication->fresh()->status);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
        Http::assertSent(fn (Request $request) => str_contains((string) ($request['fields'] ?? ''), 'call_to_action'));
    }

    #[DataProvider('ambiguousNativeResponses')]
    public function test_an_ambiguous_native_write_is_never_retried_or_replaced_with_a_photo(string $outcome): void
    {
        $publication = $this->manual();
        $writes = 0;
        Http::fake(function (Request $request) use (&$writes, $outcome) {
            if ($request->method() === 'POST') {
                $writes++;

                return match ($outcome) {
                    'timeout' => throw new ConnectionException('Sensitive URL fixture-page-token must not escape'),
                    'server_error' => Http::response(['error' => ['message' => 'private']], 503),
                    'malformed' => Http::response('not-json', 200),
                    default => Http::response(['success' => true]),
                };
            }

            return Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
        });
        $publisher = new PagePublisher(new SocialSettings, Mockery::mock(PostPreparer::class), $this->preparer());
        self::assertFalse($publisher->publishManual($publication));
        self::assertFalse($publisher->publishManual($publication));
        self::assertSame(0, $publisher->reconcileManualUnknown());
        self::assertSame(1, $writes);
        self::assertSame('unknown', $publication->fresh()->status);
        self::assertSame('publication_unknown', $publication->fresh()->error);
        self::assertNull($publication->fresh()->external_id);
    }

    public static function ambiguousNativeResponses(): array
    {
        return [['timeout'], ['server_error'], ['malformed'], ['missing_id']];
    }

    public function test_automatic_ambiguous_write_marks_both_ledgers_unknown_and_cannot_consume_another_write(): void
    {
        $this->enable();
        $post = $this->post($this->product(), ['status' => 'ready', 'scheduled_date' => '2026-10-29',
            'scheduled_for' => CarbonImmutable::now('UTC')->subMinute()]);
        $legacy = Mockery::mock(PostPreparer::class);
        $legacy->shouldReceive('prepare')->once()->andReturnUsing(fn ($claimed) => $claimed);
        $writes = 0;
        Http::fake(function (Request $request) use (&$writes) {
            if ($request->method() === 'POST') {
                $writes++;

                return Http::response(['error' => ['message' => 'secret']], 503);
            }

            return Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
        });
        $publisher = new PagePublisher(new SocialSettings, $legacy, $this->preparer());
        self::assertSame(0, $publisher->publishDue());
        self::assertSame(0, $publisher->publishDue());
        self::assertSame('unknown', $post->fresh()->status);
        self::assertSame('unknown', SocialPublication::sole()->status);
        self::assertSame(1, $writes);
    }

    public function test_legacy_photo_id_can_still_reconcile_when_native_mode_is_enabled(): void
    {
        $this->enable();
        $post = $this->post($this->product(), ['status' => 'unknown', 'post_id' => SocialSettings::PAGE_ID.'_123']);
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI'])->push($this->proof(null));
        $publisher = new PagePublisher(new SocialSettings, Mockery::mock(PostPreparer::class));
        self::assertSame(1, $publisher->reconcileUnknown());
        self::assertSame('published', $post->fresh()->status);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_native_preparation_and_sharing_revalidate_real_catalog_facts_and_rendered_artwork(): void
    {
        $publication = $this->manual();
        $photo = imagecreatetruecolor(64, 64);
        imagefill($photo, 0, 0, imagecolorallocate($photo, 80, 100, 110));
        imagepng($photo, $this->fixtureRoot.'/public/products/fixture.png');
        imagedestroy($photo);
        Route::get('/social-feed-image/{publication}/{key}', fn () => 'fixture')->name('social.feed-image');
        Route::getRoutes()->refreshNameLookups();
        $selector = new ProductSelector;
        $preparer = new PublicationPreparer($selector, new PostArtwork, new SocialSettings, new PostPlanner($selector));
        $this->meta();
        $publisher = new PagePublisher(new SocialSettings, Mockery::mock(PostPreparer::class), $preparer);
        self::assertTrue($publisher->publishManual($publication));
        $publication->refresh();
        self::assertSame([1200, 630], array_slice(getimagesize($preparer->imagePath($publication)), 0, 2));
        $links = new SocialFeedLink($preparer);
        $request = ServerRequest::create($publication->snapshot['native_feed_link']);
        self::assertNotEmpty($links->metadata($publication->product_id, $request));
        $publication->product()->update(['price' => 299]);
        self::assertSame([], $links->metadata($publication->product_id, $request));
        self::assertNull($links->imagePath($publication, $links->key($publication)));
    }

    public function test_native_automatic_get_only_recovery_updates_the_existing_mirror_without_another_write(): void
    {
        $this->enable();
        $post = $this->post($this->product(), ['status' => 'draft', 'scheduled_date' => '2026-10-29',
            'scheduled_for' => CarbonImmutable::now('UTC')->subMinute()]);
        $legacy = Mockery::mock(PostPreparer::class);
        $legacy->shouldReceive('prepare')->once()->andReturnUsing(fn ($claimed) => $claimed);
        $this->meta(wrongCta: true);
        $publisher = new PagePublisher(new SocialSettings, $legacy, $this->preparer());
        self::assertSame(0, $publisher->publishDue());
        self::assertSame('unknown', SocialPublication::sole()->status);
        $target = $post->fresh()->snapshot['native_feed_link'];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI'])->push($this->proof($target));
        config(['social.facebook_native_link' => false]);
        self::assertSame(1, $publisher->reconcileUnknown());
        self::assertSame('published', $post->fresh()->status);
        self::assertSame('published', SocialPublication::sole()->status);
        self::assertSame($post->fresh()->published_at->toIso8601String(), SocialPublication::sole()->published_at->toIso8601String());
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    private function manual(): SocialPublication
    {
        return SocialPublication::create(['origin' => 'manual', 'product_id' => $this->product(['price' => 199, 'old_price' => null])->id,
            'channel' => 'facebook_feed', 'account_id' => SocialSettings::PAGE_ID, 'request_key' => bin2hex(random_bytes(32)),
            'scheduled_for' => CarbonImmutable::now('UTC'), 'status' => 'publishing', 'phase' => 'preparing',
            'claimed_at' => CarbonImmutable::now('UTC')]);
    }

    private function preparer(): PublicationPreparer
    {
        $preparer = Mockery::mock(PublicationPreparer::class);
        $preparer->shouldReceive('prepare')->once()->andReturnUsing(function (SocialPublication $publication) {
            self::assertSame('publishing', $publication->phase);
            $publication->forceFill(['snapshot' => ['product_id' => $publication->product_id, 'price' => 199,
                'source_image_sha256' => str_repeat('a', 64), 'url' => 'https://iapi.invalid/ka/product/product-'.$publication->product_id],
                'caption' => 'Fresh factual caption', 'image_path' => 'social-publications/'.$publication->id.'-'.str_repeat('a', 16).'.png',
                'mime_type' => 'image/png'])->save();

            return $publication;
        });
        $preparer->shouldReceive('imagePath')->once()->andReturn($this->fixtureRoot.'/public/products/fixture.png');
        $preparer->shouldReceive('assertCurrent')->once();

        return $preparer;
    }

    private function meta(bool $wrongCta = false): void
    {
        $target = null;
        Http::fake(function (Request $request) use (&$target, $wrongCta) {
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/me')) {
                return Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
            }
            if ($request->method() === 'POST') {
                $target = $request['link'];

                return Http::response(['id' => SocialSettings::PAGE_ID.'_123']);
            }

            return Http::response($this->proof($wrongCta ? 'https://iapi.invalid/foreign' : $target));
        });
    }

    private function proof(?string $target): array
    {
        $proof = ['id' => SocialSettings::PAGE_ID.'_123', 'from' => ['id' => SocialSettings::PAGE_ID], 'is_published' => true,
            'permalink_url' => 'https://www.facebook.com/'.SocialSettings::PAGE_ID.'_123'];
        if ($target !== null) {
            $proof['call_to_action'] = ['type' => 'SHOP_NOW', 'value' => ['link' => $target]];
        }

        return $proof;
    }

    private function enable(): void
    {
        Setting::updateOrCreate(['key' => 'social.enabled'], ['value' => '1']);
        Setting::forgetCache();
    }
}
