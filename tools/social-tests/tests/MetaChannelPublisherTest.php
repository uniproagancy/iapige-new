<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\Setting;
use App\Models\SocialPublication;
use App\Services\Social\MetaChannelPublisher;
use App\Services\Social\PublicationPreparer;
use App\Services\Social\SocialSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class MetaChannelPublisherTest extends TestCase
{
    protected SocialSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::forgetCache();
        config(['social.page_access_token' => null]);
        if (! Schema::hasTable('social_publications')) {
            (require SOCIAL_MULTICHANNEL_PAYLOAD.'/database/migrations/2026_10_29_120000_create_social_publications.php')->up();
        }
        $this->settings = new SocialSettings;
        $this->settings->storeConnection('fixture-page-token', SocialSettings::PAGE_ID, 'IAPI');
    }

    protected function tearDown(): void
    {
        Setting::forgetCache();
        parent::tearDown();
    }

    public function test_connection_status_pins_the_linked_instagram_and_keeps_unproven_stories_disabled(): void
    {
        $this->fake();
        $status = $this->publisher()->connectionStatus();
        self::assertTrue($status['channel_status']['facebook_story']['connected']);
        self::assertTrue($status['channel_status']['instagram_feed']['connected']);
        self::assertSame(MetaChannelPublisher::INSTAGRAM_ID, $status['instagram']['id']);
        self::assertFalse($status['channel_status']['instagram_story']['connected']);
        self::assertSame('not_checked', $status['channel_status']['instagram_story']['reason']);
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' || str_contains($request->url(), 'account_type'));
    }

    #[DataProvider('invalidConnections')]
    public function test_wrong_page_changed_instagram_and_exhausted_quota_fail_closed(array $overrides, string $channel, string $reason): void
    {
        $this->fake(null, $overrides);
        $status = $this->publisher()->connectionStatus();
        self::assertFalse($status['channel_status'][$channel]['connected']);
        self::assertSame($reason, $status['channel_status'][$channel]['reason']);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public static function invalidConnections(): array
    {
        return [
            'foreign Page' => [['/v26.0/me' => ['id' => '999', 'name' => 'Foreign']], 'facebook_story', 'wrong_page'],
            'changed Instagram' => [['/v26.0/'.SocialSettings::PAGE_ID => ['instagram_business_account' => ['id' => '999']]], 'instagram_feed', 'instagram_changed'],
            'missing linked Instagram' => [['/v26.0/'.SocialSettings::PAGE_ID => []], 'instagram_feed', 'instagram_not_linked'],
            'full publishing quota' => [['/v26.0/'.MetaChannelPublisher::INSTAGRAM_ID.'/content_publishing_limit' => ['data' => [['quota_usage' => 100, 'config' => ['quota_total' => 100]]]]], 'instagram_feed', 'quota_exceeded'],
        ];
    }

    public function test_facebook_story_uploads_an_unpublished_photo_then_verifies_our_page_stories_edge(): void
    {
        $publication = $this->publication('facebook_story');
        $this->fake(function (Request $request, string $path) {
            return match ($request->method().' '.$path) {
                'POST /v26.0/'.SocialSettings::PAGE_ID.'/photos' => Http::response(['id' => '55']),
                'POST /v26.0/'.SocialSettings::PAGE_ID.'/photo_stories' => Http::response(['post_id' => '701']),
                'GET /v26.0/'.SocialSettings::PAGE_ID.'/stories' => Http::response(['data' => [['post_id' => '701', 'media_id' => '55', 'status' => 'PUBLISHED', 'url' => 'https://www.facebook.com/stories/701']]]),
                default => Http::response([], 400),
            };
        });
        self::assertTrue($this->publisher($this->preparer())->publish($publication));
        $publication->refresh();
        self::assertSame(SocialPublication::STATUS_PUBLISHED, $publication->status);
        self::assertSame('55', $publication->media_id);
        self::assertSame('701', $publication->external_id);
        self::assertSame('https://www.facebook.com/stories/701', $publication->permalink);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/photos')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? '') === 'published' && ($part['contents'] ?? '') === 'false'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/photo_stories') && $request['photo_id'] === '55');
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/'.MetaChannelPublisher::INSTAGRAM_ID.'/'));
    }

    #[DataProvider('instagramChannels')]
    public function test_instagram_publishes_only_a_finished_container_to_the_pinned_owner(string $channel): void
    {
        $this->proveInstagramStory();
        $publication = $this->publication($channel);
        $this->fake(function (Request $request, string $path) {
            return match ($request->method().' '.$path) {
                'POST /v26.0/'.MetaChannelPublisher::INSTAGRAM_ID.'/media' => Http::response(['id' => '501']),
                'GET /v26.0/501' => Http::response(['id' => '501', 'status_code' => 'FINISHED']),
                'POST /v26.0/'.MetaChannelPublisher::INSTAGRAM_ID.'/media_publish' => Http::response(['id' => '601']),
                'GET /v26.0/601' => Http::response(['id' => '601', 'owner' => ['id' => MetaChannelPublisher::INSTAGRAM_ID], 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/fixture/']),
                default => Http::response([], 400),
            };
        });
        self::assertTrue($this->publisher($this->preparer())->publish($publication));
        $publication->refresh();
        self::assertSame(SocialPublication::STATUS_PUBLISHED, $publication->status);
        self::assertSame('501', $publication->container_id);
        self::assertSame('601', $publication->external_id);
        self::assertSame('https://www.instagram.com/p/fixture/', $publication->permalink);
        Http::assertSent(function (Request $request) use ($channel) {
            if ($request->method() !== 'POST' || parse_url($request->url(), PHP_URL_PATH) !== '/v26.0/'.MetaChannelPublisher::INSTAGRAM_ID.'/media') {
                return false;
            }
            $data = $request->data();

            return ($data['image_url'] ?? '') === 'https://iapi.invalid/signed-publication-image'
                && ($channel === 'instagram_story'
                    ? ($data['media_type'] ?? '') === 'STORIES' && ! array_key_exists('caption', $data)
                    : ($data['caption'] ?? '') === 'Fresh catalog caption' && ! array_key_exists('media_type', $data));
        });
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/media_publish') && $request['creation_id'] === '501');
    }

    public static function instagramChannels(): array
    {
        return [['instagram_feed'], ['instagram_story']];
    }

    public function test_processing_instagram_container_is_resumed_without_creating_another_container(): void
    {
        $publication = $this->publication('instagram_feed');
        $containerReads = 0;
        $creates = 0;
        $publishes = 0;
        $this->fake(function (Request $request, string $path) use (&$containerReads, &$creates, &$publishes) {
            if ($request->method() === 'POST' && str_ends_with($path, '/media')) {
                $creates++;

                return Http::response(['id' => '501']);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/media_publish')) {
                $publishes++;

                return Http::response(['id' => '601']);
            }
            if ($path === '/v26.0/501') {
                return Http::response(['status_code' => ++$containerReads === 1 ? 'IN_PROGRESS' : 'FINISHED']);
            }

            return Http::response(['id' => '601', 'owner' => ['id' => MetaChannelPublisher::INSTAGRAM_ID], 'media_type' => 'IMAGE']);
        });
        $publisher = $this->publisher($this->preparer());
        self::assertFalse($publisher->publish($publication));
        self::assertSame(SocialPublication::STATUS_READY, $publication->refresh()->status);
        self::assertSame('container_ready', $publication->phase);
        self::assertSame(0, $publishes);
        $publication->forceFill(['status' => 'publishing'])->save();
        self::assertTrue($publisher->publish($publication));
        self::assertSame(1, $creates);
        self::assertSame(1, $publishes);
    }

    public function test_instagram_unknown_publish_reconciles_a_known_published_container_by_get_only(): void
    {
        $publication = $this->publication('instagram_feed');
        $published = false;
        $writes = 0;
        $this->fake(function (Request $request, string $path) use (&$published, &$writes) {
            if ($request->method() === 'POST') {
                $writes++;
                if (str_ends_with($path, '/media_publish')) {
                    throw new ConnectionException('private fixture-page-token');
                }

                return Http::response(['id' => '501']);
            }

            return Http::response(['status_code' => $published ? 'PUBLISHED' : 'FINISHED']);
        });
        $publisher = $this->publisher($this->preparer());
        self::assertFalse($publisher->publish($publication));
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $publication->refresh()->status);
        self::assertSame('501', $publication->container_id);
        self::assertNull($publication->external_id);
        self::assertFalse($publisher->publish($publication));
        $published = true;
        self::assertSame(1, $publisher->reconcileUnknown());
        self::assertSame(SocialPublication::STATUS_PUBLISHED, $publication->refresh()->status);
        self::assertSame(2, $writes, 'Reconciliation must never resend media_publish.');
    }

    public function test_foreign_instagram_readback_cannot_fall_back_to_a_published_container(): void
    {
        $publication = $this->publication('instagram_feed', ['status' => 'unknown', 'phase' => 'publishing', 'external_id' => '601', 'container_id' => '501']);
        $this->fake(fn (Request $request, string $path) => Http::response($path === '/v26.0/601'
            ? ['id' => '601', 'owner' => ['id' => '999'], 'media_type' => 'IMAGE'] : ['status_code' => 'PUBLISHED']));
        self::assertSame(0, $this->publisher()->reconcileUnknown());
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $publication->refresh()->status);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' || parse_url($request->url(), PHP_URL_PATH) === '/v26.0/501');
    }

    public function test_facebook_story_timeout_preserves_photo_id_and_reconciles_without_republishing(): void
    {
        $publication = $this->publication('facebook_story');
        $writes = 0;
        $this->fake(function (Request $request, string $path) use (&$writes) {
            if ($request->method() === 'POST') {
                $writes++;
                if (str_ends_with($path, '/photo_stories')) {
                    throw new ConnectionException('private fixture-page-token');
                }

                return Http::response(['id' => '55']);
            }

            return Http::response(['data' => [['media_id' => '55', 'post_id' => '701', 'status' => 'PUBLISHED', 'url' => 'https://www.facebook.com/stories/701']]]);
        });
        $publisher = $this->publisher($this->preparer());
        self::assertFalse($publisher->publish($publication));
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $publication->refresh()->status);
        self::assertSame('55', $publication->media_id);
        self::assertSame('publication_unknown', $publication->error);
        self::assertFalse($publisher->publish($publication));
        self::assertSame(1, $publisher->reconcileUnknown());
        self::assertSame('701', $publication->refresh()->external_id);
        self::assertSame(2, $writes);
    }

    public function test_live_lowercase_story_status_reconciles_the_exact_ids_and_url_without_another_post(): void
    {
        $publication = $this->publication('facebook_story', [
            'status' => 'unknown', 'phase' => 'publishing',
            'external_id' => '2313563462777136', 'media_id' => '1044275501965883',
        ]);
        $url = 'https://facebook.com/stories/102449546148488/UzpfSVNDOjIzMTM1NjM0NjYxMTA0Njk=/?view_single=1';
        $this->fake(fn (Request $request, string $path) => Http::response(['data' => [
            ['post_id' => '999', 'media_id' => '888', 'status' => 'published', 'url' => 'https://facebook.com/stories/unrelated'],
            ['post_id' => '2313563462777136', 'media_id' => '1044275501965883', 'status' => 'published', 'url' => $url],
        ]]));
        $publisher = $this->publisher();

        self::assertFalse($publisher->publish($publication));
        self::assertSame(1, $publisher->reconcileUnknown());
        $publication->refresh();
        self::assertSame(SocialPublication::STATUS_PUBLISHED, $publication->status);
        self::assertSame('2313563462777136', $publication->external_id);
        self::assertSame($url, $publication->permalink);
        self::assertFalse($publisher->publish($publication));
        self::assertSame(0, $publisher->reconcileUnknown());
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    #[DataProvider('ambiguousWrites')]
    public function test_ambiguous_first_instagram_write_stays_unknown_with_no_retry(int $status, mixed $body): void
    {
        $publication = $this->publication('instagram_feed');
        $writes = 0;
        $this->fake(function (Request $request) use (&$writes, $status, $body) {
            $writes++;

            return Http::response($body, $status);
        });
        $publisher = $this->publisher($this->preparer());
        self::assertFalse($publisher->publish($publication));
        self::assertSame(SocialPublication::STATUS_UNKNOWN, $publication->refresh()->status);
        self::assertSame('publication_unknown', $publication->error);
        self::assertFalse($publisher->publish($publication));
        self::assertSame(0, $publisher->reconcileUnknown());
        self::assertSame(1, $writes);
    }

    public static function ambiguousWrites(): array
    {
        return [[503, ['error' => ['message' => 'private token']]], [200, 'not-json']];
    }

    public function test_foreign_account_id_blocks_before_any_platform_write(): void
    {
        $publication = $this->publication('instagram_feed', ['account_id' => '999']);
        $this->fake();
        self::assertFalse($this->publisher()->publish($publication));
        self::assertSame(SocialPublication::STATUS_BLOCKED, $publication->refresh()->status);
        self::assertSame('account_changed', $publication->error);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_explicit_instagram_permission_failure_is_redacted_and_stops_before_media_publish(): void
    {
        $publication = $this->publication('instagram_feed');
        $this->fake(fn () => Http::response(['error' => ['code' => 200, 'message' => 'private fixture-page-token']], 400));
        self::assertFalse($this->publisher($this->preparer())->publish($publication));
        self::assertSame(SocialPublication::STATUS_FAILED, $publication->refresh()->status);
        self::assertSame('permission_missing', $publication->error);
        self::assertFalse($this->settings->status()['channel_status']['instagram_feed']['connected']);
        self::assertStringNotContainsString('fixture-page-token', json_encode($publication->toArray()));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/media_publish'));
    }

    public function test_channel_connections_never_borrow_the_pixel_token_when_the_page_token_is_missing(): void
    {
        Setting::query()->where('key', 'social.page_token')->delete();
        Setting::forgetCache();
        config(['services.facebook.access_token' => 'private-pixel-token']);
        $status = $this->publisher()->connectionStatus();
        foreach (SocialPublication::CHANNELS as $channel) {
            self::assertFalse($status['channel_status'][$channel]['connected']);
            self::assertSame('connection_missing', $status['channel_status'][$channel]['reason']);
        }
        Http::assertNothingSent();
    }

    public function test_instagram_story_requires_a_prior_controlled_capability_check_before_creating_media(): void
    {
        $publication = $this->publication('instagram_story');
        $this->fake();
        self::assertFalse($this->publisher()->publish($publication));
        self::assertSame(SocialPublication::STATUS_BLOCKED, $publication->refresh()->status);
        self::assertSame('not_checked', $publication->error);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    private function publication(string $channel, array $attributes = []): SocialPublication
    {
        return SocialPublication::create(array_replace([
            'origin' => 'manual', 'product_id' => $this->product()->id, 'request_key' => bin2hex(random_bytes(32)),
            'channel' => $channel, 'account_id' => str_starts_with($channel, 'instagram_') ? MetaChannelPublisher::INSTAGRAM_ID : SocialSettings::PAGE_ID,
            'scheduled_for' => CarbonImmutable::now('UTC'), 'status' => 'publishing', 'phase' => 'preparing',
            'claimed_at' => CarbonImmutable::now('UTC'), 'snapshot' => ['price' => 800], 'caption' => 'Fresh catalog caption',
        ], $attributes));
    }

    private function publisher(?PublicationPreparer $preparer = null): MetaChannelPublisher
    {
        return new MetaChannelPublisher($this->settings, $preparer ?? Mockery::mock(PublicationPreparer::class));
    }

    private function preparer(): PublicationPreparer
    {
        $preparer = Mockery::mock(PublicationPreparer::class);
        $preparer->shouldReceive('prepare')->once()->andReturnUsing(fn (SocialPublication $publication) => $publication);
        $preparer->shouldReceive('assertCurrent')->atLeast()->once();
        $preparer->shouldReceive('imagePath')->andReturn($this->fixtureRoot.'/public/products/fixture.png');
        $preparer->shouldReceive('mediaUrl')->andReturn('https://iapi.invalid/signed-publication-image');

        return $preparer;
    }

    private function proveInstagramStory(): void
    {
        $this->settings->recordInstagramStatus(true, 'connected', MetaChannelPublisher::INSTAGRAM_ID, 'iapi_ge');
        $this->settings->recordChannelStatus('instagram_story', true, 'connected');
    }

    private function fake(?callable $operation = null, array $overrides = []): void
    {
        $connection = array_replace([
            '/v26.0/me' => ['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI'],
            '/v26.0/'.SocialSettings::PAGE_ID => ['instagram_business_account' => ['id' => MetaChannelPublisher::INSTAGRAM_ID, 'username' => 'iapi_ge']],
            '/v26.0/'.MetaChannelPublisher::INSTAGRAM_ID => ['id' => MetaChannelPublisher::INSTAGRAM_ID, 'username' => 'iapi_ge'],
            '/v26.0/'.MetaChannelPublisher::INSTAGRAM_ID.'/content_publishing_limit' => ['data' => [['quota_usage' => 0, 'config' => ['quota_total' => 100]]]],
        ], $overrides);
        Http::fake(function (Request $request) use ($operation, $connection) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && array_key_exists($path, $connection)) {
                return Http::response($connection[$path]);
            }

            return $operation === null ? Http::response([], 400) : $operation($request, $path);
        });
    }
}
