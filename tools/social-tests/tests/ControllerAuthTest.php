<?php

declare(strict_types=1);

namespace SocialTests;

use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\SocialPublishingController;
use App\Livewire\Admin\Social\Index;
use App\Models\Setting;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\PagePublisher;
use App\Services\Social\PostArtwork;
use App\Services\Social\PostPlanner;
use App\Services\Social\PostPreparer;
use App\Services\Social\ProductSelector;
use App\Services\Social\SocialSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\View\ViewServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Drawer\Utils;
use Livewire\Features\SupportSlots\SupportSlots;
use Livewire\Mechanisms\DataStore;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SocialiteFixtureFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'socialite';
    }
}

if (! class_exists(Socialite::class)) {
    class_alias(SocialiteFixtureFacade::class, Socialite::class);
}

final class ControllerAuthTest extends TestCase
{
    protected ?User $principal = null;

    protected array $reportedExceptions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(DataStore::class, new DataStore);
        Setting::forgetCache();
        config([
            'services.facebook.client_id' => 'fixture-app',
            'services.facebook.client_secret' => 'synthetic-oauth-secret',
            'view.paths' => [SOCIAL_TEST_SOURCE_ROOT.'/resources/views'],
            'view.compiled' => $this->fixtureRoot.'/views',
        ]);
        $this->app->register(ViewServiceProvider::class);
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->andReturnUsing(function ($exception) {
            $this->reportedExceptions[] = $exception;
        });
        $this->app->instance(ExceptionHandler::class, $handler);
        $session = new Store('social-auth-fixture', new ArraySessionHandler(120));
        $session->start();
        $this->app->instance('session', $session);
        $this->app->instance('session.store', $session);
        $this->app['request']->setLaravelSession($session);
        $gate = new Gate($this->app, fn () => $this->principal);
        $gate->define('owner', fn (?User $user) => $user?->isAdmin() ?? false);
        $this->app->instance(GateContract::class, $gate);
        $auth = Mockery::mock();
        $auth->shouldReceive('id')->andReturnUsing(fn () => $this->principal?->id);
        $this->app->instance('auth', $auth);
        Route::get('/admin/social', fn () => 'fixture')->name('admin.social');
        Route::get('/', fn () => 'fixture')->name('home');
        Route::get('/account', fn () => 'fixture')->name('account');
        Route::getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        Setting::forgetCache();
        parent::tearDown();
    }

    #[DataProvider('nonOwners')]
    public function test_non_owners_cannot_invoke_any_controller_or_livewire_entry_point(?string $role): void
    {
        $this->asRole($role);
        $post = $this->post($this->product());
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldNotReceive('imagePath');
        $controller = new SocialPublishingController;
        $actions = [
            fn () => $controller->connect(new SocialSettings),
            fn () => $controller->callback(),
            fn () => $controller->artwork($post, $preparer),
            fn () => $controller->connectToken(Request::create('/admin/social/facebook/token', 'POST', ['page_token' => 'synthetic-page-token']), Mockery::mock(PagePublisher::class)),
            fn () => (new Index)->selectManualProduct(1),
            fn () => (new Index)->preparePostForSuite(1),
        ];
        foreach (['mount', 'saveConfig', 'prepareToday', 'regenerateToday', 'resetArtwork', 'refreshConnection', 'render',
            'updatedManualSearch', 'prepareForSuite', 'newManualRequest', 'publishNow'] as $method) {
            $actions[] = fn () => (new Index)->{$method}();
        }
        foreach ($actions as $action) {
            try {
                $action();
                self::fail('Only the owner may reach social publishing entry points.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
        self::assertFalse(session()->has('social.facebook_connect_owner'));
        Http::assertNothingSent();
    }

    public static function nonOwners(): iterable
    {
        yield 'guest' => [null];
        yield 'operator' => [User::OPERATOR];
        yield 'customer' => [User::CUSTOMER];
    }

    public function test_owner_connect_requires_oauth_configuration_before_creating_a_marker(): void
    {
        $this->asRole(User::ADMIN);
        config(['services.facebook.client_secret' => null]);
        $response = (new SocialPublishingController)->connect(new SocialSettings);

        self::assertSame(route('admin.social'), $response->getTargetUrl());
        self::assertSame(__('social.connection.oauth_unavailable'), session('error'));
        self::assertFalse(session()->has('social.facebook_connect_owner'));
        Http::assertNothingSent();
    }

    public function test_owner_connect_requests_page_permissions_and_records_only_an_owner_timestamp(): void
    {
        $this->asRole(User::ADMIN);
        $driver = Mockery::mock();
        $driver->shouldReceive('scopes')->once()->with(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'])->andReturnSelf();
        $driver->shouldReceive('with')->once()->with(['auth_type' => 'rerequest'])->andReturnSelf();
        $driver->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://facebook.invalid/consent'));
        $this->socialite($driver);
        $response = (new SocialPublishingController)->connect(new SocialSettings);

        self::assertSame('https://facebook.invalid/consent', $response->getTargetUrl());
        self::assertSame(['id' => 42, 'at' => now()->timestamp], session('social.facebook_connect_owner'));
        self::assertStringNotContainsString('synthetic-oauth-secret', json_encode(session()->all()));
        Http::assertNothingSent();
    }

    #[DataProvider('invalidMarkers')]
    public function test_callback_rejects_missing_expired_and_mismatched_markers_before_oauth(?array $marker): void
    {
        $this->asRole(User::ADMIN);
        if ($marker !== null) {
            session()->put('social.facebook_connect_owner', $marker);
        }
        $manager = Mockery::mock();
        $manager->shouldNotReceive('driver');
        Socialite::swap($manager);
        try {
            (new SocialPublishingController)->callback();
            self::fail('An invalid marker must reject the callback.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertFalse(session()->has('social.facebook_connect_owner'));
        Http::assertNothingSent();
    }

    public static function invalidMarkers(): iterable
    {
        yield 'missing' => [null];
        yield 'wrong owner' => [['id' => 99, 'at' => 1793246400]];
        yield 'expired' => [['id' => 42, 'at' => 0]];
        yield 'wrong ID type' => [['id' => '42', 'at' => 1793246400]];
        yield 'malformed' => [['at' => 1793246400]];
    }

    public function test_failed_oauth_state_verification_consumes_marker_and_never_exposes_exception_secrets(): void
    {
        $this->asRole(User::ADMIN);
        $this->marker();
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andThrow(new RuntimeException('Invalid OAuth state: synthetic-user-token'));
        $driver->shouldNotReceive('stateless');
        $this->socialite($driver);
        $publisher = Mockery::mock(PagePublisher::class);
        $publisher->shouldNotReceive('connectUserToken');
        $this->app->instance(PagePublisher::class, $publisher);

        $response = (new SocialPublishingController)->callback();

        self::assertSame(route('admin.social'), $response->getTargetUrl());
        self::assertSame(__('social.connection.connection_failed'), session('error'));
        self::assertFalse(session()->has('social.facebook_connect_owner'));
        self::assertStringNotContainsString('synthetic-user-token', json_encode(session()->all()));
        self::assertNull((new SocialSettings)->token());
        Http::assertNothingSent();
    }

    public function test_valid_callback_passes_verified_user_token_only_to_the_publisher(): void
    {
        $this->asRole(User::ADMIN);
        $this->marker();
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn((object) ['token' => 'synthetic-verified-user-token']);
        $driver->shouldNotReceive('stateless');
        $this->socialite($driver);
        $publisher = Mockery::mock(PagePublisher::class);
        $publisher->shouldReceive('connectUserToken')->once()->with('synthetic-verified-user-token');
        $this->app->instance(PagePublisher::class, $publisher);

        $response = (new SocialPublishingController)->callback();

        self::assertSame(route('admin.social'), $response->getTargetUrl());
        self::assertSame(__('social.connection.connected'), session('success'));
        self::assertFalse(session()->has('social.facebook_connect_owner'));
        self::assertStringNotContainsString('synthetic-verified-user-token', json_encode(session()->all()));
        Http::assertNothingSent();
    }

    public function test_failed_publisher_callback_does_not_flash_raw_tokens_or_hydrate_them_into_component_state(): void
    {
        $this->asRole(User::ADMIN);
        $this->marker();
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn((object) ['token' => 'synthetic-verified-user-token']);
        $this->socialite($driver);
        $publisher = Mockery::mock(PagePublisher::class);
        $publisher->shouldReceive('connectUserToken')->once()->andThrow(new RuntimeException('access_token=synthetic-verified-user-token'));
        $this->app->instance(PagePublisher::class, $publisher);

        (new SocialPublishingController)->callback();
        $component = new Index;
        $component->mount();

        self::assertSame(__('social.connection.connection_failed'), session('error'));
        foreach ([session()->all(), get_object_vars($component)] as $state) {
            self::assertStringNotContainsString('synthetic-verified-user-token', json_encode($state));
            self::assertStringNotContainsString('synthetic-oauth-secret', json_encode($state));
        }
        Http::assertNothingSent();
    }

    public function test_existing_facebook_sign_in_without_a_marker_keeps_its_existing_no_email_flow(): void
    {
        $this->asRole(null);
        $account = Mockery::mock();
        $account->shouldReceive('getEmail')->once()->andReturn(null);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($account);
        $this->socialite($driver);
        $publisher = Mockery::mock(PagePublisher::class);
        $publisher->shouldNotReceive('connectUserToken');
        $this->app->instance(PagePublisher::class, $publisher);

        $response = (new SocialAuthController)->callback('facebook');

        self::assertSame(route('home'), $response->getTargetUrl());
        self::assertSame(__('auth.social_no_email'), session('error'));
        Http::assertNothingSent();
    }

    public function test_facebook_sign_in_with_a_marker_uses_owner_guard_instead_of_creating_a_customer(): void
    {
        $this->asRole(User::OPERATOR);
        $this->marker();
        $manager = Mockery::mock();
        $manager->shouldNotReceive('driver');
        Socialite::swap($manager);

        $this->expectException(AuthorizationException::class);
        (new SocialAuthController)->callback('facebook');
    }

    public function test_artwork_rejects_a_foreign_page_before_reading_a_file(): void
    {
        $this->asRole(User::ADMIN);
        $post = $this->post($this->product(), ['page_id' => '999']);
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldNotReceive('imagePath');

        try {
            (new SocialPublishingController)->artwork($post, $preparer);
            self::fail('Foreign Page artwork must not be read.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_artwork_response_is_private_and_rejects_a_different_post_filename(): void
    {
        $this->asRole(User::ADMIN);
        $post = $this->post($this->product());
        $directory = storage_path('app/private/social-posts');
        mkdir($directory, 0700, true);
        $post->image_path = 'social-posts/'.$post->id.'-aaaaaaaaaaaaaaaa.png';
        copy($this->fixtureRoot.'/public/products/fixture.png', storage_path('app/private/'.$post->image_path));
        $preparer = new PostPreparer(new ProductSelector, new PostArtwork);
        $response = (new SocialPublishingController)->artwork($post, $preparer);

        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame(realpath(storage_path('app/private/'.$post->image_path)), $response->getFile()->getRealPath());

        $post->id = $post->id + 1;
        try {
            (new SocialPublishingController)->artwork($post, $preparer);
            self::fail('A post cannot read another post’s artwork file.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_owner_artwork_reset_only_changes_the_form_until_settings_are_saved(): void
    {
        $this->asRole(User::ADMIN);
        $settings = new SocialSettings;
        $settings->save(['enabled' => true, 'theme' => 'new_year', 'slots' => PostPlanner::DEFAULT_SLOTS,
            'artwork' => ['accent_color' => '#112233', 'headline' => 'ჩემი შეთავაზება', 'cta' => 'შეიძინე', 'tagline' => 'ჩემი სლოგანი']]);
        $component = new Index;
        $component->mount();
        self::assertSame('#112233', $component->artwork['accent_color']);

        $component->resetArtwork();

        self::assertSame(['accent_color' => '', 'headline' => '', 'cta' => '', 'tagline' => ''], $component->artwork);
        self::assertSame('#112233', $settings->values()['artwork']['accent_color']);
        self::assertTrue($settings->values()['enabled']);
        Http::assertNothingSent();
    }

    public function test_schedule_does_not_override_livewire_reserved_slots_and_real_slot_dehydration_succeeds(): void
    {
        $this->asRole(User::ADMIN);
        $component = new Index;
        $component->setId('social-admin-regression');
        $component->mount();

        self::assertSame(PostPlanner::DEFAULT_SLOTS, $component->postingTimes);
        self::assertArrayNotHasKey('slots', Utils::getPublicPropertiesDefinedOnSubclass($component));
        self::assertSame([], $component->getSlots());
        $component->withSlots(['header' => 'Actual Livewire slot content']);
        $hook = new SupportSlots;
        $hook->setComponent($component);
        $context = new ComponentContext($component, mounting: true);

        $hook->dehydrate($context);

        self::assertSame('header', $context->memo['slots'][0]['name']);
        self::assertSame('social-admin-regression', $context->memo['slots'][0]['componentId']);
        self::assertSame(PostPlanner::DEFAULT_SLOTS, $component->postingTimes);
        Http::assertNothingSent();
    }

    public function test_schedule_form_saves_posting_times_as_backend_slots_without_creating_another_setting(): void
    {
        $this->asRole(User::ADMIN);
        $component = new Index;
        $component->mount();
        $component->postingTimes = ['21:30', '11:30', '15:30', '13:30', '17:30'];

        $component->saveConfig();

        self::assertSame(['11:30', '13:30', '15:30', '17:30', '21:30'], (new SocialSettings)->values()['slots']);
        self::assertSame((new SocialSettings)->values()['slots'], $component->postingTimes);
        self::assertFalse(Setting::query()->where('key', 'like', '%postingTimes%')->exists());
        self::assertSame([], $component->getSlots());
        Http::assertNothingSent();
    }

    public function test_schedule_validation_reports_the_posting_times_form_field(): void
    {
        $this->asRole(User::ADMIN);
        $component = new Index;
        $component->mount();
        $component->postingTimes = ['11:00', '11:00', '13:00', '15:00', '17:00'];

        try {
            $component->saveConfig();
            self::fail('Duplicate posting times must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('postingTimes.0', $exception->errors());
            self::assertArrayNotHasKey('slots', $exception->errors());
        }
        self::assertSame(0, Setting::count());
    }

    public function test_component_rejects_an_invalid_overlay_before_saving_settings(): void
    {
        $this->asRole(User::ADMIN);
        $component = new Index;
        $component->mount();
        $component->enabled = true;
        $component->artwork['accent_color'] = '#nothex';
        $component->artwork['headline'] = '<script>bad</script>';

        try {
            $component->saveConfig();
            self::fail('Invalid artwork must be rejected before saving.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('artwork.accent_color', $exception->errors());
            self::assertArrayHasKey('artwork.headline', $exception->errors());
        }
        self::assertSame(0, Setting::count());
        Http::assertNothingSent();
    }

    public function test_regeneration_changes_only_future_drafts_and_ready_posts_using_saved_design_inside_a_transaction(): void
    {
        $this->asRole(User::ADMIN);
        $settings = new SocialSettings;
        $settings->save(['enabled' => true, 'theme' => 'new_year', 'slots' => PostPlanner::DEFAULT_SLOTS,
            'artwork' => ['headline' => 'შენახული დიზაინი']]);
        $today = now('Asia/Tbilisi')->toDateString();
        $make = fn (string $status, int $minutes, array $extra = []) => $this->post($this->product(), array_replace([
            'scheduled_for' => now('UTC')->addMinutes($minutes), 'scheduled_date' => $today,
            'status' => $status, 'theme' => 'evergreen', 'caption' => 'Original caption',
        ], $extra));
        $draft = $make('draft', 60);
        $ready = $make('ready', 90);
        $untouched = [];
        foreach (['published', 'publishing', 'unknown', 'failed', 'skipped'] as $index => $status) {
            $untouched[] = $make($status, 120 + $index * 30);
        }
        $untouched[] = $make('ready', -30);
        $untouched[] = $make('draft', 0);
        $untouched[] = $make('draft', 1440, ['scheduled_date' => now('Asia/Tbilisi')->addDay()->toDateString()]);
        $untouched[] = $make('draft', 30, ['page_id' => '999']);
        $originalTimes = SocialPost::query()->orderBy('id')->pluck('scheduled_for', 'id')->map(fn ($time) => (string) $time)->all();
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldReceive('prepare')->twice()->andReturnUsing(function (SocialPost $post) use ($draft, $ready, $settings) {
            self::assertContains($post->id, [$draft->id, $ready->id]);
            self::assertGreaterThan(0, DB::transactionLevel());
            self::assertSame('new_year', $post->theme);
            self::assertSame('შენახული დიზაინი', $settings->values()['artwork']['headline']);
            $post->forceFill(['status' => 'ready', 'caption' => 'Regenerated caption'])->save();

            return $post->refresh();
        });
        $this->app->instance(PostPreparer::class, $preparer);
        $component = new Index;
        $component->mount();
        $component->enabled = false;
        $component->theme = 'black_friday';
        $component->regenerateToday();

        self::assertEmpty($this->reportedExceptions, implode('; ', array_map(fn ($exception) => $exception->getMessage(), $this->reportedExceptions)));
        self::assertSame(['prepared' => 2, 'failed' => 0], $component->regeneration);
        self::assertTrue($settings->values()['enabled']);
        self::assertSame('new_year', $settings->values()['theme']);
        foreach ([$draft, $ready] as $post) {
            self::assertSame('ready', $post->fresh()->status);
            self::assertSame('new_year', $post->fresh()->theme);
            self::assertSame('Regenerated caption', $post->fresh()->caption);
        }
        foreach ($untouched as $post) {
            self::assertSame($post->status, $post->fresh()->status);
            self::assertSame('evergreen', $post->fresh()->theme);
            self::assertSame('Original caption', $post->fresh()->caption);
        }
        self::assertSame($originalTimes, SocialPost::query()->orderBy('id')->pluck('scheduled_for', 'id')->map(fn ($time) => (string) $time)->all());
        Http::assertNothingSent();
    }

    public function test_regeneration_rechecks_a_row_claimed_after_the_initial_candidate_query(): void
    {
        $this->asRole(User::ADMIN);
        $post = $this->post($this->product(), ['status' => 'ready', 'scheduled_date' => now('Asia/Tbilisi')->toDateString(),
            'scheduled_for' => now('UTC')->addHour()]);
        $changed = false;
        DB::listen(function ($query) use (&$changed, $post) {
            if (! $changed && str_starts_with($query->sql, 'select "id" from "social_posts"')) {
                $changed = true;
                SocialPost::query()->whereKey($post->id)->update(['status' => 'publishing']);
            }
        });
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldNotReceive('prepare');
        $this->app->instance(PostPreparer::class, $preparer);
        $component = new Index;
        $component->mount();
        $component->regenerateToday();

        self::assertTrue($changed);
        self::assertSame('publishing', $post->fresh()->status);
        self::assertSame(['prepared' => 0, 'failed' => 0], $component->regeneration);
        Http::assertNothingSent();
    }

    public function test_failed_regeneration_rolls_back_partial_database_changes_without_exposing_errors(): void
    {
        $this->asRole(User::ADMIN);
        $post = $this->post($this->product(), ['status' => 'ready', 'theme' => 'evergreen', 'caption' => 'Original caption',
            'scheduled_date' => now('Asia/Tbilisi')->toDateString(), 'scheduled_for' => now('UTC')->addHour()]);
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldReceive('prepare')->once()->andReturnUsing(function (SocialPost $post) {
            $post->forceFill(['theme' => 'new_year', 'caption' => 'Incomplete caption'])->save();
            throw new RuntimeException('synthetic-private-render-error');
        });
        $this->app->instance(PostPreparer::class, $preparer);
        $component = new Index;
        $component->mount();
        $component->regenerateToday();

        self::assertSame(['prepared' => 0, 'failed' => 1], $component->regeneration);
        self::assertSame('Original caption', $post->fresh()->caption);
        self::assertSame('evergreen', $post->fresh()->theme);
        self::assertSame('ready', $post->fresh()->status);
        self::assertStringNotContainsString('synthetic-private-render-error', json_encode(get_object_vars($component)));
        Http::assertNothingSent();
    }

    #[DataProvider('invalidPageTokens')]
    public function test_invalid_manual_page_tokens_never_reach_the_publisher_or_flash_old_input(mixed $token): void
    {
        $this->asRole(User::ADMIN);
        $request = Request::create('/admin/social/facebook/token', 'POST', ['page_token' => $token]);
        $publisher = Mockery::mock(PagePublisher::class);
        $publisher->shouldNotReceive('connectPageToken');
        $response = (new SocialPublishingController)->connectToken($request, $publisher);

        self::assertSame(route('admin.social'), $response->getTargetUrl());
        self::assertFalse($request->request->has('page_token'));
        self::assertFalse(session()->hasOldInput('page_token'));
        self::assertSame(__('social.connection.connection_missing'), session('error'));
        Http::assertNothingSent();
    }

    public static function invalidPageTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'nested' => [['synthetic-page-token']];
        yield 'short' => ['short'];
        yield 'oversized' => [str_repeat('x', 16385)];
        yield 'newline' => ["synthetic-token\nextra"];
    }

    public function test_manual_page_token_connection_sanitizes_foreign_page_and_unknown_errors_without_old_input(): void
    {
        $this->asRole(User::ADMIN);
        foreach (['wrong_page', 'access_token=synthetic-private-page-token'] as $message) {
            $request = Request::create('/admin/social/facebook/token', 'POST', ['page_token' => 'synthetic-private-page-token']);
            $publisher = Mockery::mock(PagePublisher::class);
            $publisher->shouldReceive('connectPageToken')->once()->with('synthetic-private-page-token')->andThrow(new RuntimeException($message));
            $response = (new SocialPublishingController)->connectToken($request, $publisher);

            self::assertSame(route('admin.social'), $response->getTargetUrl());
            self::assertFalse($request->request->has('page_token'));
            self::assertFalse(session()->hasOldInput('page_token'));
            self::assertSame(__('social.connection.'.($message === 'wrong_page' ? 'wrong_page' : 'connection_failed')), session('error'));
            self::assertStringNotContainsString('synthetic-private-page-token', json_encode(session()->all()));
        }
        Http::assertNothingSent();
    }

    protected function asRole(?string $role): void
    {
        $this->principal = $role === null ? null : (new User)->forceFill(['id' => 42, 'role' => $role]);
    }

    protected function marker(): void
    {
        session()->put('social.facebook_connect_owner', ['id' => 42, 'at' => now()->timestamp]);
    }

    protected function socialite(object $driver): void
    {
        $manager = Mockery::mock();
        $manager->shouldReceive('driver')->once()->with('facebook')->andReturn($driver);
        Socialite::swap($manager);
    }
}
