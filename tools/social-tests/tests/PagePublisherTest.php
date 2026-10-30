<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\Setting;
use App\Models\SocialPost;
use App\Services\Social\PagePublisher;
use App\Services\Social\PostPreparer;
use App\Services\Social\SocialSettings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class PagePublisherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::forgetCache();
        config(['social.page_access_token' => 'fixture-page-token',
            'services.facebook.client_id' => 'fixture-app', 'services.facebook.client_secret' => 'fixture-secret']);
    }

    protected function tearDown(): void
    {
        Setting::forgetCache();
        parent::tearDown();
    }

    public function test_disabled_publishing_and_missing_dedicated_token_do_not_use_the_pixel_token(): void
    {
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldNotReceive('prepare');
        $publisher = new PagePublisher(new SocialSettings, $preparer);
        self::assertSame(0, $publisher->publishDue());
        $this->enable();
        config(['social.page_access_token' => null, 'services.facebook.access_token' => 'pixel-token-must-not-be-used']);
        self::assertSame(0, $publisher->publishDue());
        self::assertSame('connection_missing', $publisher->connectionStatus()['reason']);
        Http::assertNothingSent();
    }

    public function test_connection_status_requires_the_exact_page_and_sanitizes_expired_token_errors(): void
    {
        Http::fakeSequence()->push(['id' => '999', 'name' => 'Foreign page'])->push(['error' => ['code' => 190, 'message' => 'DO NOT PERSIST fixture-page-token']], 400);
        $publisher = new PagePublisher(new SocialSettings, Mockery::mock(PostPreparer::class));
        self::assertSame('wrong_page', $publisher->connectionStatus()['reason']);
        $status = $publisher->connectionStatus();
        self::assertFalse($status['connected']);
        self::assertSame('token_expired', $status['reason']);
        self::assertStringNotContainsString('fixture-page-token', json_encode($status));
    }

    public function test_oauth_missing_permissions_stops_before_exchange_or_persistence(): void
    {
        Http::fakeSequence()->push(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]]);
        $publisher = new PagePublisher(new SocialSettings, Mockery::mock(PostPreparer::class));
        try {
            $publisher->connectUserToken('fixture-user-token');
            self::fail('Missing permissions must reject the connection.');
        } catch (RuntimeException $exception) {
            self::assertSame('permission_missing', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertFalse(Setting::query()->where('key', 'social.page_token')->exists());
        Http::assertSentCount(1);
    }

    public function test_manual_page_token_connection_needs_no_oauth_app_and_stores_only_the_encrypted_iapi_token(): void
    {
        config(['services.facebook.client_id' => null, 'services.facebook.client_secret' => null]);
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI.GE']);
        $settings = new SocialSettings;
        (new PagePublisher($settings, Mockery::mock(PostPreparer::class)))->connectPageToken('manual-page-token');
        self::assertSame('manual-page-token', $settings->token());
        self::assertNotSame('manual-page-token', Setting::query()->where('key', 'social.page_token')->value('value'));
        self::assertTrue($settings->status()['connected']);
        self::assertSame('IAPI.GE', $settings->status()['page_name']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/v26.0/me'
            && $request->hasHeader('Authorization', 'Bearer manual-page-token'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'oauth') || str_contains($request->url(), 'manual-page-token'));
    }

    public function test_manual_foreign_page_token_is_rejected_without_overwriting_the_existing_iapi_credential(): void
    {
        $settings = new SocialSettings;
        $settings->storeConnection('existing-iapi-token', SocialSettings::PAGE_ID, 'IAPI.GE');
        $cipher = Setting::query()->where('key', 'social.page_token')->value('value');
        Http::fakeSequence()->push(['id' => '999', 'name' => 'Foreign']);
        try {
            (new PagePublisher($settings, Mockery::mock(PostPreparer::class)))->connectPageToken('foreign-page-secret');
            self::fail('The foreign Page token must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('wrong_page', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertSame($cipher, Setting::query()->where('key', 'social.page_token')->value('value'));
        self::assertSame('existing-iapi-token', $settings->token());
        self::assertSame('wrong_page', $settings->status()['reason']);
    }

    public function test_manual_expired_token_errors_are_sanitized_and_invalid_tokens_never_make_a_request(): void
    {
        Http::fakeSequence()->push(['error' => ['code' => 190, 'message' => 'raw-manual-page-secret']], 400);
        $settings = new SocialSettings;
        $publisher = new PagePublisher($settings, Mockery::mock(PostPreparer::class));
        foreach (['raw-manual-page-secret' => 'token_expired', "bad\ntoken" => 'connection_missing'] as $token => $reason) {
            try {
                $publisher->connectPageToken($token);
                self::fail('An invalid or expired Page token must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame($reason, $exception->getMessage());
                self::assertNull($exception->getPrevious());
                self::assertStringNotContainsString('raw-manual-page-secret', json_encode($settings->status()));
            }
        }
        self::assertFalse(Setting::query()->where('key', 'social.page_token')->exists());
        Http::assertSentCount(1);
    }

    public function test_oauth_never_saves_a_foreign_page_token(): void
    {
        Http::fakeSequence()->push($this->permissions())->push(['access_token' => 'fixture-long-token'])
            ->push(['data' => [['id' => '999', 'name' => 'Foreign', 'access_token' => 'foreign-secret', 'tasks' => ['MANAGE']]]]);
        $publisher = new PagePublisher(new SocialSettings, Mockery::mock(PostPreparer::class));
        try {
            $publisher->connectUserToken('fixture-user-token');
            self::fail('A foreign Page must reject the connection.');
        } catch (RuntimeException $exception) {
            self::assertSame('wrong_page', $exception->getMessage());
        }
        self::assertFalse(Setting::query()->where('key', 'social.page_token')->exists());
        Http::assertSentCount(3);
    }

    #[DataProvider('pageContentTasks')]
    public function test_oauth_accepts_only_explicit_legacy_or_new_page_content_tasks(string $task, bool $allowed): void
    {
        Http::fakeSequence()->push($this->permissions())->push(['access_token' => 'fixture-long-token'])
            ->push(['data' => [['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI', 'access_token' => 'verified-page-token', 'tasks' => [$task]]]])
            ->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI.GE']);
        $settings = new SocialSettings;
        $publisher = new PagePublisher($settings, Mockery::mock(PostPreparer::class));
        if ($allowed) {
            $publisher->connectUserToken('fixture-user-token');
            self::assertSame('verified-page-token', $settings->token());
            self::assertTrue($settings->status()['connected']);
            Http::assertSentCount(4);
        } else {
            try {
                $publisher->connectUserToken('fixture-user-token');
                self::fail('A read-only Page task must not authorize publishing.');
            } catch (RuntimeException $exception) {
                self::assertSame('permission_missing', $exception->getMessage());
            }
            self::assertFalse(Setting::query()->where('key', 'social.page_token')->exists());
            Http::assertSentCount(3);
        }
    }

    public static function pageContentTasks(): array
    {
        return [
            'legacy content' => ['CREATE_CONTENT', true],
            'legacy management' => ['MANAGE', true],
            'new Page content' => ['PROFILE_PLUS_CREATE_CONTENT', true],
            'new Page management' => ['PROFILE_PLUS_MANAGE', true],
            'new Page full control' => ['PROFILE_PLUS_FULL_CONTROL', true],
            'new Page read only' => ['PROFILE_PLUS_ANALYZE', false],
        ];
    }

    public function test_oauth_paginates_only_the_fixed_endpoint_and_verifies_the_selected_page_token(): void
    {
        Http::fakeSequence()->push($this->permissions())->push(['access_token' => 'fixture-long-token'])
            ->push(['data' => [['id' => '999', 'access_token' => 'foreign-secret']],
                'paging' => ['cursors' => ['after' => 'next-cursor'], 'next' => 'https://evil.invalid/do-not-follow']])
            ->push(['data' => [['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI', 'access_token' => 'verified-page-token', 'tasks' => ['CREATE_CONTENT']]]])
            ->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI.GE']);
        $settings = new SocialSettings;
        (new PagePublisher($settings, Mockery::mock(PostPreparer::class)))->connectUserToken('fixture-user-token');
        self::assertSame('verified-page-token', $settings->token());
        self::assertNotSame('verified-page-token', Setting::query()->where('key', 'social.page_token')->value('value'));
        self::assertSame('IAPI.GE', $settings->status()['page_name']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me/accounts?') && ($request->data()['after'] ?? null) === 'next-cursor');
        Http::assertSent(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/v26.0/me' && $request->hasHeader('Authorization', 'Bearer verified-page-token'));
        Http::assertNotSent(fn (Request $request) => parse_url($request->url(), PHP_URL_HOST) !== 'graph.facebook.com');
    }

    public function test_successful_upload_is_claimed_then_verified_as_a_published_post_from_iapi(): void
    {
        $this->enable();
        $post = $this->due();
        $preparer = $this->preparer();
        Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
            '/v26.0/me' => Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']),
            '/v26.0/'.SocialSettings::PAGE_ID.'/photos' => Http::response(['id' => '123', 'post_id' => SocialSettings::PAGE_ID.'_123']),
            '/v26.0/'.SocialSettings::PAGE_ID.'_123' => Http::response(['id' => SocialSettings::PAGE_ID.'_123', 'from' => ['id' => SocialSettings::PAGE_ID], 'is_published' => true]),
            default => Http::response([], 500),
        });
        self::assertSame(1, (new PagePublisher(new SocialSettings, $preparer))->publishDue());
        $post->refresh();
        self::assertSame(SocialPost::STATUS_PUBLISHED, $post->status);
        self::assertSame(SocialSettings::PAGE_ID.'_123', $post->post_id);
        self::assertNotNull($post->published_at);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/v26.0/'.SocialSettings::PAGE_ID.'/photos'
            && $request->hasHeader('Authorization', 'Bearer fixture-page-token'));
        Http::assertSentCount(3);
    }

    public function test_foreign_page_token_prevents_any_upload_and_leaves_draft_unclaimed(): void
    {
        $this->enable();
        $post = $this->due();
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldNotReceive('prepare');
        Http::fakeSequence()->push(['id' => '999', 'name' => 'Foreign']);
        self::assertSame(0, (new PagePublisher(new SocialSettings, $preparer))->publishDue());
        self::assertSame(SocialPost::STATUS_DRAFT, $post->refresh()->status);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_transport_timeout_creates_an_unknown_outcome_and_is_never_automatically_reposted(): void
    {
        $this->enable();
        $post = $this->due();
        $uploads = 0;
        Http::fake(function (Request $request) use (&$uploads) {
            if ($request->method() === 'POST') {
                $uploads++;
                throw new ConnectionException('Raw fixture-page-token must never be persisted');
            }

            return Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
        });
        $publisher = new PagePublisher(new SocialSettings, $this->preparer());
        self::assertSame(0, $publisher->publishDue());
        self::assertSame(SocialPost::STATUS_UNKNOWN, $post->refresh()->status);
        self::assertSame('publication_unknown', $post->error);
        self::assertSame(0, $publisher->publishDue());
        self::assertSame(0, $publisher->reconcileUnknown());
        self::assertSame(1, $uploads);
    }

    public function test_server_error_and_malformed_success_responses_are_unknown_and_not_retried(): void
    {
        foreach ([Http::response(['error' => ['message' => 'private token']], 503), Http::response('not-json', 200)] as $response) {
            $this->enable();
            $post = $this->due();
            Http::fake(fn (Request $request) => $request->method() === 'POST' ? $response : Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']));
            $publisher = new PagePublisher(new SocialSettings, $this->preparer());
            self::assertSame(0, $publisher->publishDue());
            self::assertSame(SocialPost::STATUS_UNKNOWN, $post->refresh()->status);
            self::assertSame('publication_unknown', $post->error);
            self::assertSame(0, $publisher->publishDue());
        }
    }

    public function test_a_returned_id_is_not_success_until_the_read_confirms_our_page_and_published_flag(): void
    {
        $this->enable();
        $post = $this->due();
        $verified = false;
        $uploads = 0;
        Http::fake(function (Request $request) use (&$verified, &$uploads) {
            if ($request->method() === 'POST') {
                $uploads++;

                return Http::response(['post_id' => SocialSettings::PAGE_ID.'_123']);
            }
            if (parse_url($request->url(), PHP_URL_PATH) === '/v26.0/me') {
                return Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
            }

            return Http::response(['id' => SocialSettings::PAGE_ID.'_123', 'from' => ['id' => $verified ? SocialSettings::PAGE_ID : '999'], 'is_published' => true]);
        });
        $publisher = new PagePublisher(new SocialSettings, $this->preparer());
        self::assertSame(0, $publisher->publishDue());
        self::assertSame(SocialPost::STATUS_UNKNOWN, $post->refresh()->status);
        self::assertSame(SocialSettings::PAGE_ID.'_123', $post->post_id);
        self::assertNull($post->published_at);
        $verified = true;
        self::assertSame(1, $publisher->reconcileUnknown());
        self::assertSame(SocialPost::STATUS_PUBLISHED, $post->refresh()->status);
        self::assertSame(1, $uploads);
    }

    public function test_stale_claims_become_unknown_without_reposting_and_occupy_the_daily_cap(): void
    {
        $this->enable();
        $post = $this->due(['status' => SocialPost::STATUS_PUBLISHING, 'claimed_at' => CarbonImmutable::now('UTC')->subMinutes(11)]);
        $publisher = new PagePublisher(new SocialSettings, Mockery::mock(PostPreparer::class));
        self::assertSame(0, $publisher->reconcileUnknown());
        self::assertSame(SocialPost::STATUS_UNKNOWN, $post->refresh()->status);
        self::assertSame('stale_claim', $post->error);
        Http::assertNothingSent();
        foreach ([SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_UNKNOWN, SocialPost::STATUS_PUBLISHING, SocialPost::STATUS_PUBLISHED] as $status) {
            $this->due(['status' => $status]);
        }
        $draft = $this->due();
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
        self::assertSame(0, $publisher->publishDue());
        self::assertSame(SocialPost::STATUS_DRAFT, $draft->refresh()->status);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_product_revalidation_failure_skips_the_post_before_upload(): void
    {
        $this->enable();
        $post = $this->due();
        Http::fakeSequence()->push(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']);
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldReceive('prepare')->once()->andThrow(new DomainException('product_unavailable'));
        self::assertSame(0, (new PagePublisher(new SocialSettings, $preparer))->publishDue());
        self::assertSame(SocialPost::STATUS_SKIPPED, $post->refresh()->status);
        self::assertSame('product_unavailable', $post->error);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    private function enable(): void
    {
        Setting::updateOrCreate(['key' => 'social.enabled'], ['value' => '1']);
        Setting::forgetCache();
    }

    private function due(array $attributes = []): SocialPost
    {
        $index = SocialPost::query()->count();

        return $this->post($this->product(), array_replace(['status' => SocialPost::STATUS_DRAFT,
            'scheduled_for' => CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC')->addMinutes($index),
            'scheduled_date' => '2026-10-29'], $attributes));
    }

    private function preparer(): PostPreparer
    {
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldReceive('prepare')->andReturnUsing(function (SocialPost $post) {
            self::assertSame(SocialPost::STATUS_PUBLISHING, $post->status);
            self::assertNotNull($post->claimed_at);
            $post->forceFill(['caption' => 'Verified Georgian catalog caption', 'image_path' => 'social-posts/fixture.png'])->save();

            return $post;
        });
        $preparer->shouldReceive('imagePath')->andReturn($this->fixtureRoot.'/public/products/fixture.png');

        return $preparer;
    }

    private function permissions(): array
    {
        return ['data' => array_map(fn ($permission) => ['permission' => $permission, 'status' => 'granted'],
            ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'])];
    }
}
