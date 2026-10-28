<?php

declare(strict_types=1);

namespace AltaTests;

use App\Livewire\Admin\Import\Json;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaJsonAssets;
use App\Services\Import\JsonImportSource;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\LivewireServiceProvider;
use Mockery;

final class JsonUploadTest extends TestCase
{
    protected string $directory;

    protected ?User $actor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(Kernel::class, \Illuminate\Foundation\Http\Kernel::class);
        $this->app->register(LivewireServiceProvider::class);
        $this->actor = (new User)->forceFill(['id' => 73, 'role' => User::ADMIN]);
        $gate = new AccessGate($this->app, fn () => $this->actor);
        $gate->define('admin', fn (User $user) => $user->isStaff());
        $this->app->instance(GateContract::class, $gate);
        $auth = Mockery::mock(AuthFactory::class);
        $auth->shouldReceive('id')->andReturnUsing(fn () => $this->actor?->id);
        $this->app->instance(AuthFactory::class, $auth);
        $this->directory = dirname(__DIR__).'/storage/json-upload-'.bin2hex(random_bytes(6));
        config(['filesystems.default' => 'json-fixtures', 'filesystems.disks.json-fixtures' => [
            'driver' => 'local', 'root' => $this->directory, 'throw' => true,
        ], 'livewire.temporary_file_upload.directory' => 'livewire-tmp']);
        mkdir($this->directory, 0700, true);
        $this->app->instance(JsonImportSource::class, new JsonImportSource($this->directory.'/sources'));
        Supplier::create(['code' => 'alta', 'name' => 'Alta fixture', 'driver' => AltaDriver::class,
            'is_active' => true, 'priority' => 20, 'config' => ['locale' => 'ka'], 'markup' => []]);
    }

    protected function tearDown(): void
    {
        $this->removeFixtureDirectory($this->directory);
        parent::tearDown();
    }

    protected function component(): Json
    {
        $component = new Json;
        $component->code = 'alta';

        return $component;
    }

    protected function document(array $changes = []): array
    {
        return $this->details($changes) + ['raw_only_marker' => 'Never hydrate the original file body'];
    }

    protected function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    protected function upload(string $contents, string $name = 'products.json', string $mime = 'application/json'): TemporaryUploadedFile
    {
        $file = UploadedFile::fake()->createWithContent($name, $contents);
        $temporary = TemporaryUploadedFile::generateHashName($file);
        $path = FileUploadConfiguration::path($temporary, false);
        Storage::disk('json-fixtures')->put($path, $contents);
        Storage::disk('json-fixtures')->put($path.'.json', $this->encode([
            'name' => $name, 'type' => $mime, 'size' => strlen($contents), 'hash' => $temporary,
        ]));

        return new TemporaryUploadedFile($temporary, 'json-fixtures');
    }

    protected function noImporter(): ProductImporter
    {
        $importer = Mockery::mock(ProductImporter::class);
        $importer->shouldNotReceive('import');

        return $importer;
    }

    protected function rejects(callable $action, string $field): void
    {
        try {
            $action();
            self::fail('An invalid or unpreviewed import must be rejected.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }
        self::assertSame(0, DB::table('products')->count());
        self::assertSame(0, DB::table('product_offers')->count());
        Http::assertNothingSent();
    }

    public function test_valid_array_file_previews_without_catalogue_writes_or_public_file_contents(): void
    {
        $component = $this->component();
        $source = $this->encode([$this->document(), $this->document(['id' => 742, 'barCode' => '001235'])]);
        $component->upload = $this->upload("\xEF\xBB\xBF".$source);
        $component->look();

        self::assertCount(2, $component->preview);
        self::assertSame('', $component->json);
        self::assertNotNull($component->previewFingerprint);
        self::assertStringNotContainsString('Never hydrate', json_encode(get_object_vars($component), JSON_THROW_ON_ERROR));
        self::assertSame(0, DB::table('products')->count());
        self::assertSame(0, DB::table('product_offers')->count());
        Http::assertNothingSent();
    }

    public function test_save_without_preview_never_calls_importer(): void
    {
        $component = $this->component();
        $component->upload = $this->upload($this->encode($this->document()));
        $this->rejects(fn () => $component->save($this->noImporter()), 'upload');
    }

    public function test_replaced_temporary_file_is_rejected_even_when_update_hooks_are_bypassed(): void
    {
        $component = $this->component();
        $component->upload = $this->upload($this->encode($this->document()));
        $component->look();
        file_put_contents($component->upload->getRealPath(), $this->encode($this->document(['price' => 899])));

        $this->rejects(fn () => $component->save($this->noImporter()), 'upload');
        self::assertNull($component->previewFingerprint);
        self::assertSame([], $component->preview);
    }

    public function test_changed_paste_is_rejected_even_without_updated_json_hook(): void
    {
        $component = $this->component();
        $component->json = $this->encode($this->document());
        $component->look();
        $component->json = $this->encode($this->document(['price' => 899]));

        $this->rejects(fn () => $component->save($this->noImporter()), 'json');
    }

    public function test_expired_temporary_file_after_preview_never_calls_importer(): void
    {
        $component = $this->component();
        $component->upload = $this->upload($this->encode($this->document()));
        $component->look();
        $component->upload->delete();

        $this->rejects(fn () => $component->save($this->noImporter()), 'upload');
    }

    public function test_changed_supplier_is_rejected_even_without_updated_code_hook(): void
    {
        Supplier::create(['code' => 'other', 'name' => 'Other fixture', 'driver' => AltaDriver::class]);
        $component = $this->component();
        $component->json = $this->encode($this->document());
        $component->look();
        $component->code = 'other';

        $this->rejects(fn () => $component->save($this->noImporter()), 'json');
    }

    public function test_switching_identical_bytes_from_file_to_paste_still_requires_a_new_preview(): void
    {
        $component = $this->component();
        $source = $this->encode($this->document());
        $component->upload = $this->upload($source);
        $component->look();
        $component->upload = null;
        $component->json = $source;

        $this->rejects(fn () => $component->save($this->noImporter()), 'json');
    }

    public function test_wrong_extension_mime_and_over_ten_megabytes_are_rejected_before_import(): void
    {
        foreach ([
            [$this->encode($this->document()), 'products.txt', 'application/json'],
            ['<html><body>This is not a product JSON file.</body></html>', 'products.json', 'text/html'],
            [str_repeat(' ', Json::MAX_UPLOAD_KB * 1024).$this->encode($this->document()), 'products.json', 'application/json'],
        ] as [$contents, $name, $mime]) {
            $component = $this->component();
            $component->upload = $this->upload($contents, $name, $mime);
            $this->rejects(fn () => $component->look(), 'upload');
            self::assertNull($component->previewFingerprint);
        }
    }

    public function test_more_than_one_hundred_answers_are_counted_without_a_catalogue_write(): void
    {
        $component = $this->component();
        $component->upload = $this->upload($this->encode(array_fill(0, 101, $this->document())));
        $component->look();

        self::assertSame(101, $component->totalRecords);
        self::assertSame(101, $component->validRecords);
        self::assertCount(50, $component->preview);
        self::assertNotNull($component->previewFingerprint);
        self::assertSame(0, DB::table('products')->count());
    }

    public function test_malformed_json_file_never_becomes_a_valid_preview(): void
    {
        $component = $this->component();
        $component->upload = $this->upload('{"product":{"id":741,"barCode":"001234",}');

        $this->rejects(fn () => $component->look(), 'upload');
        self::assertNull($component->previewFingerprint);
    }

    public function test_ndjson_paste_remains_supported_and_forwards_only_the_previewed_products(): void
    {
        $component = $this->component();
        $component->json = $this->encode($this->document())."\n".$this->encode($this->document(['id' => 742, 'barCode' => '001235', 'price' => 699]));
        $component->look();
        self::assertCount(2, $component->preview);
        $captured = [];
        $importer = Mockery::mock(ProductImporter::class);
        $importer->shouldReceive('import')->twice()->withArgs(function (Supplier $supplier, ProductPayload $payload, bool $insist) use (&$captured) {
            $captured[] = [$supplier->code, $payload->externalId, $payload->costPrice, $insist];

            return true;
        })->andReturnNull();
        $component->save($importer);

        self::assertSame([['alta', '001234', 799.0, true], ['alta', '001235', 699.0, true]], $captured);
        self::assertSame(0, DB::table('products')->count());
        Http::assertNothingSent();
    }

    public function test_source_and_supplier_hooks_clear_old_corrections_and_preview(): void
    {
        foreach (['updatedJson', 'updatedCode', 'clearUpload'] as $hook) {
            $component = $this->component();
            $component->json = $this->encode($this->document());
            $component->look();
            $component->price = '123';
            $component->stock = '99';
            $component->{$hook}();

            self::assertNull($component->price);
            self::assertNull($component->stock);
            self::assertNull($component->previewFingerprint);
            self::assertSame([], $component->preview);
        }
    }

    public function test_selecting_a_file_clears_the_previous_paste_and_corrections(): void
    {
        $component = $this->component();
        $component->json = $this->encode($this->document(['price' => 123]));
        $component->look();
        $component->upload = $this->upload($this->encode($this->document(['price' => 899])));
        $component->updatedUpload();

        self::assertSame('', $component->json);
        self::assertNull($component->stock);
        self::assertNull($component->price);
        self::assertNull($component->previewFingerprint);
        $component->look();
        self::assertSame(899.0, $component->preview[0]['price']);
    }

    public function test_single_file_preview_allows_validated_deliberate_price_and_zero_stock_corrections(): void
    {
        $component = $this->component();
        $component->upload = $this->upload($this->encode($this->document()));
        $component->look();
        $component->price = '750';
        $component->stock = '0';
        $importer = Mockery::mock(ProductImporter::class);
        $importer->shouldReceive('import')->once()->withArgs(fn (Supplier $supplier, ProductPayload $payload, bool $insist) => $supplier->code === 'alta' && $payload->externalId === '001234' && $payload->costPrice === 750.0
            && $payload->stock === 0 && count($payload->images) === 2 && $insist)->andReturnNull();

        $component->save($importer);
        self::assertSame(0, DB::table('products')->count());
        Http::assertNothingSent();
    }

    protected function photoDocument(): array
    {
        $url = 'https://imgstore.alta.ge/images/synthetic-product.png';

        return $this->document(['imageUrl' => $url, 'images' => [$url]])
            + ['iapiAssets' => [['url' => $url, 'base64' => $this->pngBase64()]]];
    }

    protected function savedProduct(int $images = 0): Product
    {
        $this->app['router']->get('/admin/products/{product}/edit', fn () => 'fixture')->name('admin.products.edit');
        $this->app['router']->getRoutes()->refreshNameLookups();
        DB::table('products')->insert(['id' => 29, 'sku' => 'alta-001234', 'price' => 799, 'stock' => 7, 'status' => Product::STATUS_DRAFT]);

        for ($i = 0; $i < $images; $i++) {
            DB::table('product_images')->insert(['product_id' => 29, 'path' => 'fixture-'.$i.'.png']);
        }

        $product = Product::findOrFail(29);
        $product->setRelation('translations', collect([new ProductTranslation(['locale' => 'ka', 'name' => 'Fixture product'])]));

        return $product;
    }

    public function test_optional_bundled_photos_preview_without_writes_and_invalid_bytes_disable_that_row(): void
    {
        $assets = Mockery::mock(AltaJsonAssets::class)->makePartial();
        $assets->shouldNotReceive('store');
        $assets->shouldNotReceive('verify');
        $this->app->instance(AltaJsonAssets::class, $assets);
        $component = $this->component();
        $component->upload = $this->upload($this->encode([$this->photoDocument()]));
        $component->look();

        self::assertTrue($component->preview[0]['ok']);
        self::assertNotNull($component->previewFingerprint);
        self::assertSame(0, DB::table('products')->count());
        $invalid = $this->photoDocument();
        $invalid['iapiAssets'][0]['base64'] = 'not raster bytes';
        $component->upload = $this->upload($this->encode([$invalid]));
        $component->look();

        self::assertFalse($component->preview[0]['ok']);
        self::assertNull($component->previewFingerprint);
        self::assertNotEmpty($component->problems);
        $this->rejects(fn () => $component->save($this->noImporter()), 'upload');
    }

    public function test_validated_bundled_photos_are_stored_before_import_and_verified_afterward(): void
    {
        $component = $this->component();
        $document = $this->photoDocument();
        $component->upload = $this->upload($this->encode([$document]));
        $component->look();
        $sequence = [];
        $product = $this->savedProduct(1);
        $assets = Mockery::mock(AltaJsonAssets::class)->makePartial();
        $assets->shouldReceive('store')->once()->withArgs(function (Supplier $supplier, array $bytes) use (&$sequence, $document) {
            $sequence[] = 'store';

            return $supplier->code === 'alta' && $bytes === [$document['product']['imageUrl'] => base64_decode($this->pngBase64(), true)];
        });
        $assets->shouldReceive('verify')->once()->with($product, Mockery::type(Supplier::class), [$document['product']['imageUrl']])
            ->andReturnUsing(function () use (&$sequence) {
                $sequence[] = 'verify';

                return true;
            });
        $this->app->instance(AltaJsonAssets::class, $assets);
        $importer = Mockery::mock(ProductImporter::class);
        $importer->shouldReceive('import')->once()->andReturnUsing(function () use (&$sequence, $product) {
            $sequence[] = 'import';

            return $product;
        });
        $component->save($importer);

        self::assertSame(['store', 'import', 'verify'], $sequence);
        self::assertTrue($component->result[0]['photos_complete']);
        self::assertSame([], $component->problems);
        self::assertNull($component->upload);
        self::assertNull($component->previewFingerprint);
        Http::assertNothingSent();
    }

    public function test_url_only_import_reports_missing_photos_instead_of_unqualified_success(): void
    {
        $component = $this->component();
        $component->json = $this->encode($this->document());
        $component->look();
        $product = $this->savedProduct();
        $importer = Mockery::mock(ProductImporter::class);
        $importer->shouldReceive('import')->once()->andReturn($product);
        $component->save($importer);

        self::assertFalse($component->result[0]['photos_complete']);
        self::assertSame(0, $component->result[0]['images']);
        self::assertNotEmpty($component->problems);
        $events = \Livewire\store($component)->get('dispatched');
        self::assertSame('warning', end($events)->serialize()['params']['type']);
        Http::assertNothingSent();
    }

    public function test_enough_existing_photos_do_not_hide_a_failed_bundled_content_verification(): void
    {
        $component = $this->component();
        $component->upload = $this->upload($this->encode([$this->photoDocument()]));
        $component->look();
        $product = $this->savedProduct(1);
        $assets = Mockery::mock(AltaJsonAssets::class)->makePartial();
        $assets->shouldReceive('store')->once();
        $assets->shouldReceive('verify')->once()->andReturnFalse();
        $this->app->instance(AltaJsonAssets::class, $assets);
        $importer = Mockery::mock(ProductImporter::class);
        $importer->shouldReceive('import')->once()->andReturn($product);
        $component->save($importer);

        self::assertSame(1, $component->result[0]['images']);
        self::assertFalse($component->result[0]['photos_complete']);
        self::assertNotEmpty($component->problems);
        $events = \Livewire\store($component)->get('dispatched');
        self::assertSame('warning', end($events)->serialize()['params']['type']);
        Http::assertNothingSent();
    }

    protected function sourceFile(string $contents, int $userId = 73, string $supplier = 'alta'): array
    {
        $source = app(JsonImportSource::class);
        $meta = $source->create($userId, $supplier, 'complete-products.json', strlen($contents));
        $temporary = tempnam(sys_get_temp_dir(), 'json-component-');

        try {
            for ($offset = 0; $offset < strlen($contents); $offset += JsonImportSource::CHUNK_BYTES) {
                file_put_contents($temporary, substr($contents, $offset, JsonImportSource::CHUNK_BYTES));
                $source->append($userId, $meta['id'], $offset, $temporary);
            }

            return $source->finalize($userId, $meta['id']);
        } finally {
            unlink($temporary);
        }
    }

    public function test_large_private_source_has_no_ten_megabyte_or_hundred_product_limit_and_preview_stays_bounded(): void
    {
        $documents = [];
        for ($i = 0; $i < 111; $i++) {
            $documents[] = $this->document(['id' => 1000 + $i, 'barCode' => (string) (100000 + $i)])
                + ['large_original_field' => str_repeat('x', 100000)];
        }
        $encoded = $this->encode($documents);
        self::assertGreaterThan(10 * 1024 * 1024, strlen($encoded));
        $meta = $this->sourceFile($encoded);
        $component = $this->component();
        $component->useSource($meta['id']);
        self::assertSame('', $component->json);
        $step = $component->look();
        self::assertFalse($step['done']);
        self::assertSame(10, $step['processed']);
        self::assertNull($component->previewFingerprint);
        $calls = 1;
        while (! $step['done']) {
            $step = $component->previewNext();
            $calls++;
        }

        self::assertSame(12, $calls);
        self::assertSame(111, $component->totalRecords);
        self::assertSame(111, $component->validRecords);
        self::assertTrue($component->previewComplete);
        self::assertNotNull($component->previewFingerprint);
        self::assertCount(50, $component->preview);
        self::assertStringNotContainsString('large_original_field', json_encode(get_object_vars($component)));
        self::assertSame(0, DB::table('products')->count());
        Http::assertNothingSent();
    }

    public function test_saving_requires_the_full_file_preview_and_runs_only_three_records_per_step(): void
    {
        $documents = [];
        for ($i = 0; $i < 11; $i++) {
            $documents[] = $this->document(['id' => 1000 + $i, 'barCode' => (string) (100000 + $i), 'images' => [], 'imageUrl' => '']);
        }
        $meta = $this->sourceFile($this->encode($documents));
        $component = $this->component();
        $component->useSource($meta['id']);
        $component->look();
        $this->rejects(fn () => $component->save($this->noImporter()), 'upload');
        $component->previewNext();
        $product = $this->savedProduct();
        $seen = [];
        $importer = Mockery::mock(ProductImporter::class);
        $importer->shouldReceive('import')->times(11)->andReturnUsing(function (Supplier $supplier, ProductPayload $payload) use (&$seen, $product) {
            $seen[] = $payload->externalId;

            return $product;
        });
        $step = $component->save($importer);
        self::assertFalse($step['done']);
        self::assertSame(3, $step['processed']);
        self::assertSame(3, $component->savedCount);
        while (! $step['done']) {
            $step = $component->saveNext($importer);
        }

        self::assertSame(array_column(array_column($documents, 'product'), 'barCode'), $seen);
        self::assertSame(11, $component->saveProcessed);
        self::assertSame(11, $component->savedCount);
        self::assertTrue($component->saveComplete);
        self::assertNull($component->previewFingerprint);
        self::assertSame(['done' => true, 'processed' => 11], $component->saveNext($this->noImporter()));
        Http::assertNothingSent();
    }

    public function test_truncated_source_never_unlocks_save_even_after_some_valid_preview_rows(): void
    {
        $json = '['.implode(',', array_fill(0, 11, $this->encode($this->document())));
        $meta = $this->sourceFile($json);
        $component = $this->component();
        $component->useSource($meta['id']);
        $component->look();
        self::assertSame(10, $component->totalRecords);
        $this->rejects(fn () => $component->previewNext(), 'upload');
        self::assertFalse($component->previewComplete);
        self::assertNull($component->previewFingerprint);
        $this->rejects(fn () => $component->save($this->noImporter()), 'upload');
    }

    public function test_foreign_owner_and_supplier_sources_cannot_attach_to_the_component(): void
    {
        $foreignOwner = $this->sourceFile($this->encode([$this->document()]), userId: 74);
        $foreignSupplier = $this->sourceFile($this->encode([$this->document()]), supplier: 'other');
        $component = $this->component();
        $this->rejects(fn () => $component->useSource($foreignOwner['id']), 'upload');
        $this->rejects(fn () => $component->useSource($foreignSupplier['id']), 'upload');
        self::assertNull($component->sourceId);
    }

    public function test_unfinished_upload_cannot_bind_a_private_source_or_unlock_preview(): void
    {
        $meta = app(JsonImportSource::class)->create(73, 'alta', 'not-finished.json', 1000);
        $component = $this->component();
        $this->rejects(fn () => $component->useSource($meta['id']), 'upload');
        self::assertNull($component->sourceId);
        self::assertNull($component->sourceSha);
        self::assertNull($component->previewFingerprint);
    }

    public function test_semantic_errors_do_not_grow_public_arrays_or_hide_a_valid_record_after_the_sample(): void
    {
        $bad = $this->document(['name' => '', 'price' => 0]);
        $meta = $this->sourceFile($this->encode([...array_fill(0, 60, $bad), $this->document()]));
        $component = $this->component();
        $component->useSource($meta['id']);
        $step = $component->look();
        while (! $step['done']) {
            $step = $component->previewNext();
        }
        self::assertSame(61, $component->totalRecords);
        self::assertSame(1, $component->validRecords);
        self::assertSame(120, $component->errorCount);
        self::assertCount(50, $component->preview);
        self::assertCount(20, $component->problems);
        self::assertNotNull($component->previewFingerprint);
        self::assertSame(0, DB::table('products')->count());
    }

    public function test_guest_and_customer_cannot_invoke_import_actions(): void
    {
        foreach ([null, (new User)->forceFill(['id' => 74, 'role' => User::CUSTOMER])] as $actor) {
            $this->actor = $actor;
            foreach (['look', 'previewNext', 'clearUpload'] as $method) {
                try {
                    $this->component()->{$method}();
                    self::fail('Import actions require a staff member.');
                } catch (AuthorizationException) {
                    self::assertSame(0, DB::table('products')->count());
                }
            }
        }
        $this->actor = (new User)->forceFill(['id' => 73, 'role' => User::OPERATOR]);
        $component = $this->component();
        $component->json = $this->encode($this->document());
        self::assertTrue($component->look()['done']);
    }
}
