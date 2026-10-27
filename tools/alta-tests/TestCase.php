<?php

declare(strict_types=1);

namespace AltaTests;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(__DIR__);
        $this->app->useAppPath(ALTA_TEST_SOURCE_ROOT.'/app');
        $this->app->instance('config', new Repository([
            'app' => ['env' => 'testing', 'locale' => 'ka', 'fallback_locale' => 'en', 'timezone' => 'UTC'],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
            'logging' => ['default' => 'import', 'channels' => ['import' => [
                'driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class,
            ]]],
            'shop' => ['import_timeout' => 300],
            'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            'view' => ['paths' => [ALTA_TEST_SOURCE_ROOT.'/resources/views'], 'compiled' => __DIR__.'/storage/views'],
            'services' => ['alta' => ['timeout' => 1, 'connect_timeout' => 1, 'token' => 'synthetic-fixture-token']],
        ]));
        $this->app->instance('env', 'testing');
        $this->app->instance('request', Request::create('https://iapi.invalid/fixture'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        foreach ([
            \Illuminate\Events\EventServiceProvider::class,
            \Illuminate\Foundation\Providers\FoundationServiceProvider::class,
            \Illuminate\Database\DatabaseServiceProvider::class,
            \Illuminate\Cache\CacheServiceProvider::class,
            \Illuminate\Filesystem\FilesystemServiceProvider::class,
            \Illuminate\Log\LogServiceProvider::class,
            \Illuminate\Translation\TranslationServiceProvider::class,
            \Illuminate\Validation\ValidationServiceProvider::class,
            \Illuminate\View\ViewServiceProvider::class,
            \Illuminate\Bus\BusServiceProvider::class,
            \Illuminate\Queue\QueueServiceProvider::class,
        ] as $provider) {
            $this->app->register($provider);
        }
        $this->app->boot();
        $this->app['translation.loader']->addPath(ALTA_TEST_SOURCE_ROOT.'/lang');
        Http::preventStrayRequests();
        Queue::fake();
        $this->schema();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        $this->app['db']->disconnect();
        $this->app->flush();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    protected function details(array $product = [], array $stores = []): array
    {
        return ['product' => array_replace([
            'id' => 741,
            'barCode' => '001234',
            'name' => 'Synthetic Alta Laptop',
            'price' => 799.0,
            'previousPrice' => 999.0,
            'brandName' => 'Synthetic Brand',
            'categoryName' => 'Laptops',
            'description' => '<p>Synthetic exact-product description.</p>',
            'storageQuantity' => 7,
            'imageUrl' => 'https://images.invalid/primary.webp',
            'images' => ['https://images.invalid/primary.webp', 'https://images.invalid/side.webp'],
            'specificationGroup' => [['specifications' => [[
                'specificationName' => 'Memory', 'specificationMeaning' => '16 GB', 'isMainSpecification' => true,
            ]]]],
        ], $product), 'availabilityInStores' => $stores];
    }

    protected function pngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9kAAAAASUVORK5CYII=';
    }

    protected function removeFixtureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $allowed = str_replace('\\', '/', (string) realpath(__DIR__.'/storage'));
        $resolved = str_replace('\\', '/', (string) realpath($directory));
        if ($allowed === '' || ! str_starts_with(strtolower($resolved), strtolower($allowed).'/')) {
            throw new \RuntimeException('Refusing cleanup outside the isolated fixture storage.');
        }
        \Illuminate\Support\Facades\File::deleteDirectory($resolved);
    }

    protected function schema(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name'); $t->string('driver');
            $t->boolean('is_active')->default(true); $t->unsignedInteger('priority')->default(20);
            $t->json('config')->nullable(); $t->json('markup')->nullable(); $t->timestamp('last_run_at')->nullable(); $t->timestamps();
        });
        Schema::create('supplier_stocks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('supplier_id'); $t->string('external_id');
            $t->integer('quantity'); $t->decimal('cost_price', 10, 2)->nullable(); $t->json('data')->nullable();
            $t->timestamp('synced_at')->nullable(); $t->timestamps(); $t->unique(['supplier_id', 'external_id']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->string('sku'); $t->decimal('price', 10, 2); $t->decimal('old_price', 10, 2)->nullable();
            $t->decimal('cost_price', 10, 2)->nullable(); $t->boolean('price_lock')->default(false);
            $t->integer('stock'); $t->string('status')->default('active'); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('product_offers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('supplier_id'); $t->string('external_id');
            $t->decimal('cost_price', 10, 2); $t->decimal('old_cost_price', 10, 2)->nullable(); $t->integer('stock');
            $t->timestamp('synced_at')->nullable(); $t->timestamps(); $t->unique(['supplier_id', 'external_id']);
        });
        Schema::create('product_images', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id'); $t->string('path'); $t->integer('sort_order')->default(0); $t->timestamps();
        });
        Schema::create('promotions', function (Blueprint $t) {
            $t->id(); $t->boolean('is_active')->default(true); $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable(); $t->integer('sort_order')->default(0); $t->timestamps();
        });
        Schema::create('promotion_product', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('promotion_id'); $t->unsignedBigInteger('product_id');
            $t->decimal('promo_price', 10, 2)->nullable(); $t->integer('discount_percent')->default(0);
            $t->integer('stock_limit')->nullable(); $t->integer('sold')->default(0); $t->integer('sort_order')->default(0); $t->timestamps();
        });
    }
}
