<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\Language;
use App\Models\Product;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\TestCase as BaseTestCase;

class LocalizationFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravellocalization';
    }
}

class FixtureApplication extends Application
{
    public function resourcePath($path = '')
    {
        return $this->joinPaths(SOCIAL_TEST_SOURCE_ROOT.'/resources', $path);
    }
}

abstract class TestCase extends BaseTestCase
{
    protected Application $app;

    protected string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir().'/iapi-social-tests-'.bin2hex(random_bytes(8));
        mkdir($this->fixtureRoot.'/public/products', 0777, true);
        $this->app = new FixtureApplication(__DIR__);
        $this->app->useAppPath(SOCIAL_TEST_SOURCE_ROOT.'/app');
        $this->app->usePublicPath(SOCIAL_TEST_SOURCE_ROOT.'/public');
        $this->app->useStoragePath($this->fixtureRoot.'/storage');
        $this->app->instance('config', new Repository([
            'app' => ['env' => 'testing', 'locale' => 'ka', 'fallback_locale' => 'en', 'timezone' => 'UTC', 'key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'cipher' => 'AES-256-CBC'],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
            'filesystems' => ['default' => 'public', 'disks' => [
                'public' => ['driver' => 'local', 'root' => $this->fixtureRoot.'/public', 'url' => 'https://iapi.invalid/storage', 'throw' => true],
                'local' => ['driver' => 'local', 'root' => $this->fixtureRoot.'/private', 'throw' => true],
            ]],
            'logging' => ['default' => 'testing', 'channels' => ['testing' => [
                'driver' => 'monolog', 'handler' => NullHandler::class,
            ]]],
            'shop' => ['instalment_months' => 24],
            'social' => [
                'page_id' => '103447072710661', 'api_version' => 'v26.0', 'page_access_token' => null,
                'slots' => ['10:00', '13:00', '16:00', '19:00', '21:00'],
            ],
        ]));
        $this->app->instance('env', 'testing');
        $this->app->instance('request', Request::create('https://iapi.invalid/fixture'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        foreach ([
            EventServiceProvider::class,
            FoundationServiceProvider::class,
            DatabaseServiceProvider::class,
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
            LogServiceProvider::class,
            RoutingServiceProvider::class,
            TranslationServiceProvider::class,
            ValidationServiceProvider::class,
            EncryptionServiceProvider::class,
        ] as $provider) {
            $this->app->register($provider);
        }
        $this->app->instance('laravellocalization', new class
        {
            public function getLocalizedURL(string $locale, string $url, array $attributes = [], bool $forceDefaultLocation = false): string
            {
                return str_replace('https://iapi.invalid/', 'https://iapi.invalid/'.$locale.'/', $url);
            }
        });
        $this->app->boot();
        $this->app['translation.loader']->addPath(SOCIAL_TEST_SOURCE_ROOT.'/lang');
        Route::get('/product/{slug}', fn () => 'fixture')->name('product');
        Route::getRoutes()->refreshNameLookups();
        Http::preventStrayRequests();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 08:00:00', 'Asia/Tbilisi'));
        Carbon::setTestNow(CarbonImmutable::getTestNow());
        $this->schema();
        Language::flushCache();
        Storage::disk('public')->put('products/fixture.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9kAAAAASUVORK5CYII='));
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        Language::flushCache();
        $resolved = realpath($this->fixtureRoot);
        $temp = realpath(sys_get_temp_dir());
        if ($resolved !== false && $temp !== false
            && str_starts_with(strtolower(str_replace('\\', '/', $resolved)), strtolower(rtrim(str_replace('\\', '/', $temp), '/')).'/iapi-social-tests-')) {
            File::deleteDirectory($resolved);
        }
        $this->app['db']->disconnect();
        $this->app->flush();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    protected function product(array $attributes = [], ?string $image = 'products/fixture.png'): Product
    {
        $id = DB::table('products')->insertGetId(array_replace([
            'category_id' => 1, 'brand_id' => null, 'sku' => bin2hex(random_bytes(5)),
            'price' => 800, 'old_price' => 1000, 'stock' => 3, 'status' => Product::STATUS_ACTIVE,
            'is_preorder' => false, 'published_at' => null,
        ], $attributes));
        DB::table('product_translations')->insert([
            'product_id' => $id, 'locale' => 'ka', 'name' => 'პროდუქტი '.$id, 'slug' => 'product-'.$id,
        ]);
        if ($image !== null) {
            DB::table('product_images')->insert(['product_id' => $id, 'path' => $image, 'sort_order' => 0]);
        }

        return Product::findOrFail($id);
    }

    protected function promotion(Product $product, array $pivot, array $attributes = []): void
    {
        $id = DB::table('promotions')->insertGetId(array_replace([
            'is_active' => true, 'starts_at' => null, 'ends_at' => null, 'sort_order' => 0,
        ], $attributes));
        DB::table('promotion_product')->insert(array_replace([
            'promotion_id' => $id, 'product_id' => $product->id, 'promo_price' => null,
            'discount_percent' => null, 'sort_order' => 0,
        ], $pivot));
    }

    protected function post(Product $product, array $attributes = []): SocialPost
    {
        return SocialPost::create(array_replace([
            'page_id' => '103447072710661', 'product_id' => $product->id,
            'scheduled_for' => CarbonImmutable::parse('2026-10-28 06:00:00', 'UTC'),
            'scheduled_date' => '2026-10-28', 'status' => SocialPost::STATUS_PUBLISHED,
            'theme' => 'evergreen', 'snapshot' => ['product_id' => $product->id, 'category_id' => $product->category_id],
        ], $attributes));
    }

    protected function schema(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->boolean('is_default');
            $table->boolean('is_active');
            $table->integer('sort_order')->default(0);
        });
        DB::table('languages')->insert(['code' => 'ka', 'is_default' => true, 'is_active' => true]);
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('sku');
            $table->decimal('price', 10, 2);
            $table->decimal('old_price', 10, 2)->nullable();
            $table->integer('stock');
            $table->string('status');
            $table->boolean('is_preorder')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('product_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('locale');
            $table->string('name');
            $table->string('slug');
        });
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('path');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('promotion_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('promo_price', 10, 2)->nullable();
            $table->integer('discount_percent')->nullable();
            $table->integer('stock_limit')->nullable();
            $table->integer('sold')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        (require SOCIAL_TEST_SOURCE_ROOT.'/database/migrations/2026_10_29_090000_create_social_posts_table.php')->up();
    }
}
