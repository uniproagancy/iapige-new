<?php

declare(strict_types=1);

namespace SocialTests;

use App\Livewire\Admin\Social\Index;
use App\Models\Setting;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\BusinessSuitePreparer;
use App\Services\Social\MultiChannelPublisher;
use App\Services\Social\PostPlanner;
use App\Services\Social\SocialSettings;
use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\View\ViewServiceProvider;
use Livewire\Mechanisms\DataStore;
use Mockery;
use ReflectionMethod;

final class ManualPublishingUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(DataStore::class, new DataStore);
        Setting::forgetCache();
        config(['view.paths' => [SOCIAL_MULTICHANNEL_PAYLOAD.'/resources/views', SOCIAL_TEST_SOURCE_ROOT.'/resources/views'],
            'view.compiled' => $this->fixtureRoot.'/views']);
        $this->app->register(ViewServiceProvider::class);
        $owner = (new User)->forceFill(['id' => 42, 'role' => User::ADMIN]);
        $gate = new Gate($this->app, fn () => $owner);
        $gate->define('owner', fn (User $user) => $user->isAdmin());
        $this->app->instance(GateContract::class, $gate);
        $auth = Mockery::mock();
        $auth->shouldReceive('id')->andReturn(42);
        $this->app->instance('auth', $auth);
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->andReturnNull();
        $this->app->instance(ExceptionHandler::class, $handler);
    }

    protected function tearDown(): void
    {
        Setting::forgetCache();
        parent::tearDown();
    }

    public function test_selection_allows_an_active_in_stock_product_without_an_automatic_discount_or_price_threshold(): void
    {
        $product = $this->product(['price' => 100, 'old_price' => null]);
        $component = new Index;
        $component->mount();
        $component->selectManualProduct($product->id);

        self::assertSame($product->id, $component->manualProductId);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $component->manualRequestKey);
        self::assertSame([], $component->getSlots());
        foreach ([['stock' => 0], ['status' => 'draft'], ['is_preorder' => true]] as $changes) {
            $unavailable = $this->product($changes);
            $component->selectManualProduct($unavailable->id);
            self::assertNull($component->manualProductId);
            self::assertTrue($component->getErrorBag()->has('manualProductId'));
        }
        Http::assertNothingSent();
    }

    public function test_the_request_key_survives_same_product_selection_and_preview_but_changes_for_another_product(): void
    {
        $product = $this->product();
        $other = $this->product();
        $component = new Index;
        $component->mount();
        $component->selectManualProduct($product->id);
        $key = $component->manualRequestKey;
        $component->updatedManualSearch();
        $component->selectManualProduct($product->id);
        self::assertSame($key, $component->manualRequestKey);
        $preparer = Mockery::mock(BusinessSuitePreparer::class);
        $preparer->shouldReceive('prepareProduct')->once()->with($product->id, 42)->andReturn(['id' => str_repeat('a', 64)]);
        $this->app->instance(BusinessSuitePreparer::class, $preparer);
        $component->prepareForSuite();
        self::assertSame($key, $component->manualRequestKey);

        $component->selectManualProduct($other->id);
        self::assertNotSame($key, $component->manualRequestKey);
        self::assertSame($other->id, $component->manualRequestProductId);
        self::assertNull($component->suiteBundle);
        self::assertSame(0, SocialPost::count());
        Http::assertNothingSent();
    }

    public function test_repeated_publish_clicks_keep_the_same_key_and_outcomes_expose_no_raw_error_or_secret_url(): void
    {
        (new SocialSettings)->save(['enabled' => false, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS,
            'channels' => array_fill_keys(SocialSettings::CHANNELS, true)]);
        $product = $this->product();
        $component = new Index;
        $component->mount();
        $component->selectManualProduct($product->id);
        $key = $component->manualRequestKey;
        $publisher = Mockery::mock(MultiChannelPublisher::class);
        $publisher->shouldReceive('publishProduct')->twice()->with($product->id, 42, $key, SocialSettings::CHANNELS)->andReturn([
            'request_key' => $key, 'published' => 1, 'unknown' => 1, 'blocked' => 0,
            'publications' => [
                ['channel' => 'facebook_feed', 'status' => 'published', 'permalink' => 'https://www.facebook.com/103447072710661_123', 'error' => null],
                ['channel' => 'instagram_feed', 'status' => 'unknown', 'permalink' => 'https://www.instagram.com/p/test/?access_token=synthetic-private-token', 'error' => 'synthetic-private-token'],
                ['channel' => 'facebook_story', 'status' => 'published', 'permalink' => 'https://www.facebook.com/post?access_token=synthetic-private-token', 'error' => null],
            ],
        ]);
        $this->app->instance(MultiChannelPublisher::class, $publisher);
        $component->publishNow();
        $component->publishNow();

        self::assertSame($key, $component->manualRequestKey);
        self::assertSame('https://www.facebook.com/103447072710661_123', $component->manualOutcome['publications']['facebook_feed']['permalink']);
        self::assertNull($component->manualOutcome['publications']['instagram_feed']['permalink']);
        self::assertNull($component->manualOutcome['publications']['facebook_story']['permalink']);
        self::assertStringNotContainsString('synthetic-private-token', json_encode(get_object_vars($component)));
        $component->newManualRequest();
        self::assertSame($key, $component->manualRequestKey);
        self::assertTrue($component->getErrorBag()->has('manualPublishing'));
        self::assertSame(0, SocialPost::count());
        Http::assertNothingSent();
    }

    public function test_publish_now_respects_saved_channels_independently_of_the_automatic_switch(): void
    {
        $product = $this->product();
        $component = new Index;
        $component->mount();
        $component->selectManualProduct($product->id);
        $publisher = Mockery::mock(MultiChannelPublisher::class);
        $publisher->shouldReceive('publishProduct')->once()->with($product->id, 42, $component->manualRequestKey, ['facebook_feed'])
            ->andReturn(['published' => 1, 'unknown' => 0, 'blocked' => 0,
                'publications' => [['channel' => 'facebook_feed', 'status' => 'published', 'permalink' => null, 'error' => null]]]);
        $this->app->instance(MultiChannelPublisher::class, $publisher);

        $component->publishNow();

        self::assertSame(1, $component->manualOutcome['published']);
        self::assertFalse((new SocialSettings)->values()['enabled']);
        self::assertSame(0, SocialPost::count());
        Http::assertNothingSent();
    }

    public function test_a_tampered_selected_product_cannot_reuse_another_products_request_key(): void
    {
        $product = $this->product();
        $other = $this->product();
        $component = new Index;
        $component->mount();
        $component->selectManualProduct($product->id);
        $component->manualProductId = $other->id;
        $publisher = Mockery::mock(MultiChannelPublisher::class);
        $publisher->shouldNotReceive('publishProduct');
        $this->app->instance(MultiChannelPublisher::class, $publisher);

        $component->publishNow();

        self::assertTrue($component->getErrorBag()->has('manualProductId'));
        Http::assertNothingSent();
    }

    public function test_product_search_accepts_an_exact_id_and_excludes_unavailable_products(): void
    {
        $product = $this->product(['price' => 50, 'old_price' => null]);
        $component = new Index;
        $component->mount();
        $component->manualSearch = (string) $product->id;
        $matches = (new ReflectionMethod($component, 'manualMatches'))->invoke($component);
        self::assertContains($product->id, $matches->pluck('id')->all());
        Http::assertNothingSent();
    }

    public function test_ui_will_not_activate_an_unverified_new_channel_but_allows_a_verified_one(): void
    {
        $component = new Index;
        $component->mount();
        $component->channels['instagram_feed'] = true;
        $component->saveConfig();
        self::assertTrue($component->getErrorBag()->has('channels.instagram_feed'));
        self::assertSame(0, Setting::count());
        $settings = new SocialSettings;
        $settings->storeConnection('synthetic-page-token', SocialSettings::PAGE_ID, 'IAPI');
        $settings->recordInstagramStatus(true, 'connected', '178414000000001', 'iapi.shop', 'BUSINESS');
        $settings->recordChannelStatus('instagram_feed', true, 'connected');

        $component->saveConfig();

        self::assertTrue($settings->values()['channels']['instagram_feed']);
        self::assertNotNull($settings->values()['channel_enabled_at']['instagram_feed']);
        Http::assertNothingSent();
    }
}
