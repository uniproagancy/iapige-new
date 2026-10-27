<?php

declare(strict_types=1);

namespace AltaTests;

use App\Livewire\Admin\Import\Json;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaJsonAssets;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(Kernel::class, \Illuminate\Foundation\Http\Kernel::class);
        $this->app->register(LivewireServiceProvider::class);
        $this->directory = dirname(__DIR__).'/storage/json-upload-'.bin2hex(random_bytes(6));
        config(['filesystems.default' => 'json-fixtures', 'filesystems.disks.json-fixtures' => [
            'driver' => 'local', 'root' => $this->directory, 'throw' => true,
        ], 'livewire.temporary_file_upload.directory' => 'livewire-tmp']);
        mkdir($this->directory, 0700, true);
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

    public function test_more_than_one_hundred_answers_is_rejected_without_a_preview_or_import(): void
    {
        $component = $this->component();
        $component->upload = $this->upload($this->encode(array_fill(0, Json::MAX_ANSWERS + 1, $this->document())));

        $this->rejects(fn () => $component->look(), 'upload');
        self::assertNull($component->previewFingerprint);
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
}
