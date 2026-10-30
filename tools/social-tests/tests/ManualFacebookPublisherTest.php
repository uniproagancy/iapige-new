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
use App\Services\Social\SocialSettings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class ManualFacebookPublisherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['translation.loader']->addPath(SOCIAL_MULTICHANNEL_PAYLOAD.'/lang');
        Setting::forgetCache();
        config(['social.page_access_token' => 'fixture-page-token']);
        if (! Schema::hasTable('social_publications')) {
            (require SOCIAL_MULTICHANNEL_PAYLOAD.'/database/migrations/2026_10_29_120000_create_social_publications.php')->up();
        }
    }

    protected function tearDown(): void
    {
        Setting::forgetCache();
        parent::tearDown();
    }

    public function test_explicit_manual_post_can_publish_a_regular_low_price_product_while_automatic_posts_are_disabled(): void
    {
        $publication = $this->publication();
        $this->successfulMeta();
        $publisher = $this->publisher($this->preparer());
        self::assertFalse((new SocialSettings)->values()['enabled']);
        self::assertTrue($publisher->publishManual($publication));
        $publication->refresh();
        self::assertSame(SocialPublication::STATUS_PUBLISHED, $publication->status);
        self::assertSame('published', $publication->phase);
        self::assertSame('55', $publication->media_id);
        self::assertSame(SocialSettings::PAGE_ID.'_123', $publication->external_id);
        self::assertSame('https://www.facebook.com/'.SocialSettings::PAGE_ID.'/posts/123', $publication->permalink);
        self::assertNotNull($publication->published_at);
        self::assertNull($publication->error);
        self::assertSame(199.0, (float) $publication->snapshot['price']);
        self::assertSame(0, SocialPost::query()->count(), 'Manual actions must not consume an automatic planning slot.');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/v26.0/'.SocialSettings::PAGE_ID.'/photos'
            && $request->hasHeader('Authorization', 'Bearer fixture-page-token')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'caption' && ($part['contents'] ?? null) === 'Fresh catalog price: 199 ₾'));
        Http::assertSentCount(3);
    }

    public function test_phase_compare_and_set_prevents_reentrant_and_repeated_publication_requests(): void
    {
        $publication = $this->publication();
        $this->successfulMeta();
        $publisher = null;
        $preparer = $this->preparer(function (SocialPublication $claimed) use (&$publisher) {
            self::assertFalse($publisher->publishManual($claimed->fresh()), 'A second worker must not prepare or POST the same claimed publication.');
        });
        $publisher = $this->publisher($preparer);
        self::assertTrue($publisher->publishManual($publication));
        self::assertFalse($publisher->publishManual($publication));
        Http::assertSentCount(3);
    }

    public function test_real_preparer_rebuilds_the_manual_artwork_and_caption_from_current_regular_price(): void
    {
        if (! extension_loaded('gd') || ! function_exists('imagettftext')) {
            $this->markTestSkipped('The real manual artwork integration requires GD and FreeType.');
        }
        $publication = $this->publication();
        $publication->product()->update(['price' => 299, 'old_price' => null]);
        $picture = imagecreatetruecolor(64, 64);
        imagefill($picture, 0, 0, imagecolorallocate($picture, 80, 100, 110));
        imagepng($picture, $this->fixtureRoot.'/public/products/fixture.png');
        imagedestroy($picture);
        $selector = new ProductSelector;
        $settings = new SocialSettings;
        $preparer = new PublicationPreparer($selector, new PostArtwork, $settings, new PostPlanner($selector));
        $this->successfulMeta();
        $published = $this->publisher($preparer)->publishManual($publication);
        self::assertTrue($published, (string) $publication->fresh()->error);
        $publication->refresh();
        self::assertSame(299.0, (float) $publication->snapshot['price']);
        self::assertSame(0, $publication->snapshot['discount_percent']);
        self::assertStringContainsString('299 ₾', $publication->caption);
        self::assertStringNotContainsString('999', $publication->caption);
        $path = $preparer->imagePath($publication);
        self::assertNotNull($path);
        self::assertSame([1080, 1350], array_slice(getimagesize($path), 0, 2));
    }

    public function test_a_last_moment_catalog_change_is_detected_before_the_upload(): void
    {
        $publication = $this->publication();
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
        $preparer = $this->preparer(null, function (SocialPublication $prepared) {
            $prepared->product()->update(['stock' => 0]);
            throw new DomainException('publication_stale');
        });
        self::assertFalse($this->publisher($preparer)->publishManual($publication));
        self::assertSame(SocialPublication::STATUS_SKIPPED, $publication->refresh()->status);
        self::assertSame('publication_stale', $publication->error);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    #[DataProvider('invalidClaims')]
    public function test_only_a_correctly_claimed_manual_iapi_facebook_feed_row_can_attempt_http(array $attributes): void
    {
        $publication = $this->publication($attributes);
        $preparer = Mockery::mock(PublicationPreparer::class);
        $preparer->shouldNotReceive('prepare');
        self::assertFalse($this->publisher($preparer)->publishManual($publication));
        Http::assertNothingSent();
    }

    public static function invalidClaims(): array
    {
        return [
            'automatic origin' => [['origin' => SocialPublication::ORIGIN_AUTOMATIC]],
            'wrong channel' => [['channel' => 'instagram_feed']],
            'foreign Page' => [['account_id' => '999']],
            'unclaimed row' => [['status' => SocialPublication::STATUS_READY]],
            'missing claim timestamp' => [['claimed_at' => null]],
            'upload already started' => [['phase' => 'publishing']],
            'ambiguous phase' => [['phase' => 'unknown']],
            'ambiguous status' => [['status' => SocialPublication::STATUS_UNKNOWN]],
            'known post ID' => [['external_id' => SocialSettings::PAGE_ID.'_123']],
            'known uploaded photo' => [['media_id' => '55']],
        ];
    }

    public function test_a_fresh_product_revalidation_failure_skips_before_any_upload(): void
    {
        $publication = $this->publication();
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
        $preparer = Mockery::mock(PublicationPreparer::class);
        $preparer->shouldReceive('prepare')->once()->andThrow(new DomainException('product_unavailable'));
        self::assertFalse($this->publisher($preparer)->publishManual($publication));
        self::assertSame(SocialPublication::STATUS_SKIPPED, $publication->refresh()->status);
        self::assertSame('product_unavailable', $publication->error);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_a_transport_timeout_is_unknown_and_can_never_be_automatically_reposted(): void
    {
        $publication = $this->publication();
        $uploads = 0;
        Http::fake(function (Request $request) use (&$uploads) {
            if ($request->method() === 'POST') {
                $uploads++;
                throw new ConnectionException('private fixture-page-token must not escape');
            }

            return Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
        });
        $publisher = $this->publisher($this->preparer());
        self::assertFalse($publisher->publishManual($publication));
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $publication->refresh()->status);
        self::assertSame('unknown', $publication->phase);
        self::assertSame('publication_unknown', $publication->error);
        self::assertNull($publication->external_id);
        self::assertFalse($publisher->publishManual($publication));
        self::assertSame(1, $uploads);
    }

    #[DataProvider('ambiguousResponses')]
    public function test_server_errors_and_malformed_success_are_unknown_and_not_retried(int $status, mixed $body): void
    {
        $publication = $this->publication();
        $uploads = 0;
        Http::fake(function (Request $request) use (&$uploads, $status, $body) {
            if ($request->method() === 'POST') {
                $uploads++;

                return Http::response($body, $status);
            }

            return Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
        });
        $publisher = $this->publisher($this->preparer());
        self::assertFalse($publisher->publishManual($publication));
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $publication->refresh()->status);
        self::assertSame('publication_unknown', $publication->error);
        self::assertFalse($publisher->publishManual($publication));
        self::assertSame(1, $uploads);
    }

    public static function ambiguousResponses(): array
    {
        return ['server failure' => [503, ['error' => ['message' => 'private token']]], 'malformed success' => [200, 'not JSON']];
    }

    #[DataProvider('unconfirmedPosts')]
    public function test_received_ids_are_preserved_but_publication_needs_strict_owned_page_readback(array $readback): void
    {
        $publication = $this->publication();
        $this->successfulMeta($readback);
        $publisher = $this->publisher($this->preparer());
        self::assertFalse($publisher->publishManual($publication));
        $publication->refresh();
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $publication->status);
        self::assertSame('unknown', $publication->phase);
        self::assertSame('55', $publication->media_id);
        self::assertSame(SocialSettings::PAGE_ID.'_123', $publication->external_id);
        self::assertSame('verification_failed', $publication->error);
        self::assertNull($publication->published_at);
        self::assertNull($publication->permalink);
        self::assertFalse($publisher->publishManual($publication));
        Http::assertSentCount(3);
    }

    public static function unconfirmedPosts(): array
    {
        return [
            'foreign author' => [['from' => ['id' => '999']]],
            'unpublished' => [['is_published' => false]],
            'string published flag' => [['is_published' => 'true']],
            'different returned object' => [['id' => SocialSettings::PAGE_ID.'_999']],
        ];
    }

    public function test_explicit_permission_rejection_is_failed_with_only_a_safe_reason(): void
    {
        $publication = $this->publication();
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI'])
            ->push(['error' => ['code' => 200, 'message' => 'private fixture-page-token']], 400);
        self::assertFalse($this->publisher($this->preparer())->publishManual($publication));
        self::assertSame(SocialPublication::STATUS_FAILED, $publication->refresh()->status);
        self::assertSame('permission_missing', $publication->error);
        self::assertFalse((new SocialSettings)->status()['connected']);
        self::assertStringNotContainsString('fixture-page-token', json_encode($publication->toArray()));
        Http::assertSentCount(2);
    }

    public function test_missing_page_token_and_foreign_page_identity_cannot_use_pixel_token_or_upload(): void
    {
        $preparer = Mockery::mock(PublicationPreparer::class);
        $preparer->shouldNotReceive('prepare');
        $publisher = $this->publisher($preparer);
        config(['social.page_access_token' => null, 'services.facebook.access_token' => 'private-pixel-token']);
        $missing = $this->publication();
        self::assertFalse($publisher->publishManual($missing));
        self::assertSame('connection_missing', $missing->refresh()->error);
        Http::assertNothingSent();
        config(['social.page_access_token' => 'foreign-page-token']);
        Http::fakeSequence()->push(['id' => '999', 'name' => 'Foreign']);
        $foreign = $this->publication();
        self::assertFalse($publisher->publishManual($foreign));
        self::assertSame('wrong_page', $foreign->refresh()->error);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_untrusted_permalink_is_not_saved_even_after_confirmed_owned_publication(): void
    {
        $publication = $this->publication();
        $this->successfulMeta(['permalink_url' => 'https://malicious.invalid/private-token']);
        self::assertTrue($this->publisher($this->preparer())->publishManual($publication));
        self::assertNull($publication->refresh()->permalink);
    }

    public function test_known_manual_unknown_ids_reconcile_by_get_only_without_reposting_missing_or_foreign_rows(): void
    {
        $known = $this->publication(['status' => SocialPublication::STATUS_UNKNOWN, 'phase' => 'unknown',
            'media_id' => '55', 'external_id' => SocialSettings::PAGE_ID.'_123']);
        $missing = $this->publication(['status' => SocialPublication::STATUS_UNKNOWN, 'phase' => 'unknown', 'error' => 'publication_unknown']);
        $foreign = $this->publication(['status' => SocialPublication::STATUS_UNKNOWN, 'phase' => 'unknown',
            'external_id' => '999_123', 'account_id' => '999']);
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI'])
            ->push(['id' => SocialSettings::PAGE_ID.'_123', 'from' => ['id' => SocialSettings::PAGE_ID],
                'is_published' => true, 'permalink_url' => 'https://www.facebook.com/'.SocialSettings::PAGE_ID.'/posts/123']);
        $preparer = Mockery::mock(PublicationPreparer::class);
        $preparer->shouldNotReceive('prepare');
        self::assertSame(1, $this->publisher($preparer)->reconcileManualUnknown());
        self::assertSame(SocialPublication::STATUS_PUBLISHED, $known->refresh()->status);
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $missing->refresh()->status);
        self::assertSame('publication_unknown', $missing->error);
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $foreign->refresh()->status);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_manual_reconciliation_keeps_unpublished_readback_unknown_without_a_new_post(): void
    {
        $publication = $this->publication(['status' => SocialPublication::STATUS_UNKNOWN, 'phase' => 'unknown',
            'media_id' => '55', 'external_id' => SocialSettings::PAGE_ID.'_123']);
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI'])
            ->push(['id' => SocialSettings::PAGE_ID.'_123', 'from' => ['id' => SocialSettings::PAGE_ID], 'is_published' => false]);
        self::assertSame(0, $this->publisher(Mockery::mock(PublicationPreparer::class))->reconcileManualUnknown());
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $publication->refresh()->status);
        self::assertSame('verification_failed', $publication->error);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_manual_reconciliation_recovers_only_stale_claims_for_the_fixed_facebook_page(): void
    {
        $stale = ['status' => 'publishing', 'phase' => 'verifying', 'claimed_at' => CarbonImmutable::now('UTC')->subMinutes(11)];
        $known = $this->publication(array_replace($stale, ['media_id' => '55', 'external_id' => SocialSettings::PAGE_ID.'_123']));
        $missing = $this->publication($stale);
        $fresh = $this->publication(['claimed_at' => CarbonImmutable::now('UTC')->subMinutes(9)]);
        $foreign = $this->publication(array_replace($stale, ['account_id' => '999']));
        $automatic = $this->publication(array_replace($stale, ['origin' => 'automatic']));
        $otherChannel = $this->publication(array_replace($stale, ['channel' => 'instagram_feed']));
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI'])
            ->push(['id' => SocialSettings::PAGE_ID.'_123', 'from' => ['id' => SocialSettings::PAGE_ID], 'is_published' => true]);

        self::assertSame(1, $this->publisher(Mockery::mock(PublicationPreparer::class))->reconcileManualUnknown());
        self::assertSame(SocialPublication::STATUS_PUBLISHED, $known->refresh()->status);
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $missing->refresh()->status);
        self::assertSame('unknown', $missing->phase);
        self::assertSame('stale_claim', $missing->error);
        foreach ([$fresh, $foreign, $automatic, $otherChannel] as $untouched) {
            self::assertSame(SocialPublication::STATUS_PUBLISHING, $untouched->refresh()->status);
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_a_stale_manual_claim_without_a_returned_id_becomes_unknown_without_http_or_a_retry(): void
    {
        $publication = $this->publication(['phase' => 'publishing', 'claimed_at' => CarbonImmutable::now('UTC')->subMinutes(11)]);
        $publisher = $this->publisher(Mockery::mock(PublicationPreparer::class));
        self::assertSame(0, $publisher->reconcileManualUnknown());
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $publication->refresh()->status);
        self::assertSame('stale_claim', $publication->error);
        self::assertFalse($publisher->publishManual($publication));
        Http::assertNothingSent();
    }

    public function test_manual_reconciliation_reaches_a_recoverable_26th_record_and_wraps_without_any_post(): void
    {
        for ($index = 1; $index <= 26; $index++) {
            $last = $this->publication(['status' => 'unknown', 'phase' => 'unknown',
                'external_id' => SocialSettings::PAGE_ID.'_'.$index]);
        }
        $this->fairReadback();
        $publisher = $this->publisher(Mockery::mock(PublicationPreparer::class));
        self::assertSame(0, $publisher->reconcileManualUnknown());
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $last->fresh()->status);
        self::assertSame(1, $publisher->reconcileManualUnknown());
        self::assertSame(SocialPublication::STATUS_PUBLISHED, $last->fresh()->status);
        Http::assertSentCount(28);
        self::assertSame(0, $publisher->reconcileManualUnknown(), 'After reaching the highest ID the next batch must wrap to older unresolved records.');
        Http::assertSentCount(54);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_legacy_reconciliation_also_reaches_the_26th_unknown_post_with_frozen_timestamps(): void
    {
        Setting::updateOrCreate(['key' => 'social.enabled'], ['value' => '1']);
        Setting::forgetCache();
        for ($index = 1; $index <= 26; $index++) {
            $last = $this->post($this->product(), ['status' => SocialPost::STATUS_UNKNOWN,
                'post_id' => SocialSettings::PAGE_ID.'_'.$index,
                'scheduled_for' => CarbonImmutable::now('UTC')->subDay()->startOfDay()->addMinutes($index)]);
        }
        $this->fairReadback();
        $publisher = $this->publisher(Mockery::mock(PublicationPreparer::class));
        self::assertSame(0, $publisher->reconcileUnknown());
        self::assertSame(SocialPost::STATUS_UNKNOWN, $last->fresh()->status);
        self::assertSame(1, $publisher->reconcileUnknown());
        self::assertSame(SocialPost::STATUS_PUBLISHED, $last->fresh()->status);
        Http::assertSentCount(28);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    private function publisher(PublicationPreparer $preparer): PagePublisher
    {
        return new PagePublisher(new SocialSettings, Mockery::mock(PostPreparer::class), $preparer);
    }

    private function publication(array $attributes = []): SocialPublication
    {
        $product = $this->product(['price' => 199, 'old_price' => null]);

        return SocialPublication::create(array_replace([
            'origin' => SocialPublication::ORIGIN_MANUAL, 'product_id' => $product->id,
            'request_key' => bin2hex(random_bytes(32)), 'channel' => 'facebook_feed', 'account_id' => SocialSettings::PAGE_ID,
            'scheduled_for' => CarbonImmutable::now('UTC'), 'status' => SocialPublication::STATUS_PUBLISHING,
            'phase' => 'preparing', 'claimed_at' => CarbonImmutable::now('UTC'),
            'snapshot' => ['price' => 999], 'caption' => 'Stale preview caption',
        ], $attributes));
    }

    private function preparer(?callable $duringPrepare = null, ?callable $duringCurrent = null): PublicationPreparer
    {
        $preparer = Mockery::mock(PublicationPreparer::class);
        $preparer->shouldReceive('prepare')->once()->andReturnUsing(function (SocialPublication $publication) use ($duringPrepare) {
            self::assertSame(SocialPublication::STATUS_PUBLISHING, $publication->status);
            self::assertSame('publishing', $publication->phase);
            self::assertNotNull($publication->claimed_at);
            if ($duringPrepare !== null) {
                $duringPrepare($publication);
            }
            $price = (float) $publication->product()->firstOrFail()->price;
            $publication->forceFill(['snapshot' => ['price' => $price], 'caption' => 'Fresh catalog price: '.money($price),
                'image_path' => 'social-publications/fixture.png', 'mime_type' => 'image/png'])->save();

            return $publication;
        });
        $preparer->shouldReceive('imagePath')->once()->andReturn($this->fixtureRoot.'/public/products/fixture.png');
        $preparer->shouldReceive('assertCurrent')->once()->andReturnUsing(function (SocialPublication $publication) use ($duringCurrent) {
            if ($duringCurrent !== null) {
                $duringCurrent($publication);
            }
        });

        return $preparer;
    }

    private function successfulMeta(array $readback = []): void
    {
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI'])
            ->push(['id' => '55', 'post_id' => SocialSettings::PAGE_ID.'_123'])
            ->push(array_replace(['id' => SocialSettings::PAGE_ID.'_123', 'from' => ['id' => SocialSettings::PAGE_ID], 'is_published' => true,
                'permalink_url' => 'https://www.facebook.com/'.SocialSettings::PAGE_ID.'/posts/123'], $readback));
    }

    private function fairReadback(): void
    {
        Http::fake(function (Request $request) {
            $id = basename(parse_url($request->url(), PHP_URL_PATH));
            if ($id === 'me') {
                return Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
            }

            return Http::response(['id' => $id, 'from' => ['id' => SocialSettings::PAGE_ID],
                'is_published' => $id === SocialSettings::PAGE_ID.'_26']);
        });
    }
}
