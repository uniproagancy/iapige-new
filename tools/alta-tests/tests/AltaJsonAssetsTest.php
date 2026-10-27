<?php

declare(strict_types=1);

namespace AltaTests;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaImageCache;
use App\Services\Import\Drivers\Alta\AltaJsonAssets;
use App\Services\Import\ImageDownloader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class AltaJsonAssetsTest extends TestCase
{
    protected string $directory;

    protected AltaJsonAssets $assets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = dirname(__DIR__).'/storage/json-assets-'.bin2hex(random_bytes(6));
        config(['services.alta.retail_directory' => $this->directory.'/private',
            'filesystems.default' => 'public', 'filesystems.disks.public' => [
                'driver' => 'local', 'root' => $this->directory.'/public', 'throw' => true,
            ]]);
        mkdir($this->directory, 0700, true);
        $this->assets = new AltaJsonAssets;
    }

    protected function tearDown(): void
    {
        $this->removeFixtureDirectory($this->directory);
        parent::tearDown();
    }

    protected function url(int $number = 1): string
    {
        return 'https://imgstore.alta.ge/images/synthetic-'.$number.'.png';
    }

    protected function document(array $assets): string
    {
        return json_encode(['product' => ['id' => 741, 'barCode' => '001234'], 'iapiAssets' => $assets], JSON_THROW_ON_ERROR);
    }

    protected function asset(?string $url = null, ?string $bytes = null): array
    {
        return ['url' => $url ?? $this->url(), 'base64' => base64_encode($bytes ?? base64_decode($this->pngBase64(), true))];
    }

    protected function supplier(bool $alta = true): Supplier
    {
        return Supplier::create(['code' => $alta ? 'alta' : 'other', 'name' => 'Synthetic supplier',
            'driver' => $alta ? AltaDriver::class : 'OtherDriver', 'config' => [], 'markup' => [], 'is_active' => true]);
    }

    protected function product(Supplier $supplier): Product
    {
        DB::table('products')->insert(['id' => 26, 'sku' => 'alta-001234', 'price' => 799, 'stock' => 3]);
        DB::table('product_offers')->insert(['product_id' => 26, 'supplier_id' => $supplier->id,
            'external_id' => '001234', 'cost_price' => 799, 'stock' => 3]);

        return Product::findOrFail(26);
    }

    protected function downloader(): ImageDownloader
    {
        return new class extends ImageDownloader
        {
            public function __construct()
            {
                self::$failures = [];
            }

            public function storeFixture(Product $product, string $url): ?string
            {
                return parent::store($product, $url, 0);
            }
        };
    }

    public function test_old_api_documents_without_iapi_assets_remain_compatible(): void
    {
        self::assertNull($this->assets->validate(json_encode($this->details()), [$this->url()]));
        self::assertNull($this->assets->validate('{"product":{"id":741},"assets":"raw API field"}', [$this->url()]));
        self::assertFalse(is_dir($this->directory.'/private'));
        Http::assertNothingSent();
    }

    public function test_preview_validates_exact_unique_photo_mapping_without_writing_files_or_database(): void
    {
        $bytes = base64_decode($this->pngBase64(), true);
        DB::enableQueryLog();
        $valid = $this->assets->validate($this->document([$this->asset()]), ['', $this->url(), $this->url()]);
        self::assertSame([$this->url() => $bytes], $valid);
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        self::assertFalse(is_dir($this->directory.'/private'));
        self::assertFalse(is_dir($this->directory.'/public'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_asset_container_must_be_a_list_and_not_null_or_a_scalar(): void
    {
        foreach ([null, 'bytes', ['named' => $this->asset()]] as $assets) {
            try {
                $this->assets->validate(json_encode(['product' => ['id' => 741], 'iapiAssets' => $assets]), [$this->url()]);
                self::fail('An explicit malformed asset container must not be treated as absent.');
            } catch (ValidationException) {
                self::assertFalse(is_dir($this->directory.'/private'));
            }
        }
        Http::assertNothingSent();
    }

    #[DataProvider('unsafeUrls')]
    public function test_bundled_foreign_or_nonpublic_image_addresses_are_rejected(string $url): void
    {
        try {
            $this->assets->validate($this->document([$this->asset($url)]), [$url]);
            self::fail('The bundled address must be a plain public Alta image URL.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('iapiAssets', $error->errors());
        }
        self::assertFalse(is_dir($this->directory.'/private'));
        Http::assertNothingSent();
    }

    public static function unsafeUrls(): array
    {
        return [
            'foreign' => ['https://foreign.invalid/images/a.png'],
            'private' => ['https://127.0.0.1/images/a.png'],
            'http' => ['http://imgstore.alta.ge/images/a.png'],
            'port' => ['https://imgstore.alta.ge:443/images/a.png'],
            'credentials' => ['https://user:pass@imgstore.alta.ge/images/a.png'],
            'proxy' => ['https://alta.ge/_next/image?url=https://foreign.invalid/a.png'],
            'query' => ['https://imgstore.alta.ge/images/a.png?redirect=1'],
            'fragment' => ['https://imgstore.alta.ge/images/a.png#fragment'],
            'traversal' => ['https://imgstore.alta.ge/images/%2e%2e/a.png'],
            'encoded slash traversal' => ['https://imgstore.alta.ge/images/a%2f..%2f..%2fb.png'],
            'control' => ['https://imgstore.alta.ge/images/a%00.png'],
            'svg' => ['https://imgstore.alta.ge/images/a.svg'],
        ];
    }

    #[DataProvider('invalidAssetLists')]
    public function test_invalid_asset_shapes_raise_validation_errors(mixed $assets): void
    {
        $this->expectException(ValidationException::class);
        $this->assets->validate($this->document($assets === [] ? [] : [$assets]), [$this->url()]);
    }

    public static function invalidAssetLists(): array
    {
        return ['missing photo' => [[]], 'string entry' => ['bytes'], 'null entry' => [null],
            'numeric URL' => [['url' => 12, 'base64' => 'aaa']],
            'numeric base64' => [['url' => 'https://imgstore.alta.ge/images/synthetic-1.png', 'base64' => 12]],
            'invalid base64' => [['url' => 'https://imgstore.alta.ge/images/synthetic-1.png', 'base64' => '!!!']]];
    }

    public function test_all_requested_photos_are_required_and_unknown_or_duplicate_entries_are_rejected(): void
    {
        foreach ([[$this->asset()], [$this->asset(), $this->asset()], [$this->asset($this->url(3))]] as $assets) {
            try {
                $this->assets->validate($this->document($assets), [$this->url(), $this->url(2)]);
                self::fail('Missing, duplicated or unknown photos must not be accepted as a complete bundle.');
            } catch (ValidationException) {
                self::assertFalse(is_dir($this->directory.'/private'));
            }
        }
        Http::assertNothingSent();
    }

    public function test_only_the_first_eight_unique_nonblank_images_are_bundled(): void
    {
        $urls = array_map(fn ($n) => $this->url($n), range(1, 9));
        $assets = array_map(fn ($url) => $this->asset($url), array_slice($urls, 0, 8));
        self::assertCount(8, $this->assets->validate($this->document($assets), ['', $urls[0], ...$urls]));
        $this->expectException(ValidationException::class);
        $this->assets->validate($this->document([...$assets, $this->asset($urls[8])]), $urls);
    }

    public function test_html_and_svg_cannot_be_stored_as_photo_bytes(): void
    {
        foreach (['<!doctype html><title>Access denied</title>', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'] as $bytes) {
            try {
                $this->assets->validate($this->document([$this->asset(bytes: $bytes)]), [$this->url()]);
                self::fail('The asset must contain raster image bytes.');
            } catch (ValidationException) {
                self::assertFalse(is_dir($this->directory.'/private'));
            }
        }
    }

    public function test_individual_and_total_raw_byte_caps_are_enforced(): void
    {
        $png = base64_decode($this->pngBase64(), true);
        $oversized = $png.str_repeat('x', 1048576);
        $medium = $png.str_repeat('x', 750000);
        foreach ([[$this->asset(bytes: $oversized)], array_map(fn ($n) => $this->asset($this->url($n), $medium), range(1, 3))] as $assets) {
            try {
                $this->assets->validate($this->document($assets), array_column($assets, 'url'));
                self::fail('Raw byte limits apply before any cache write.');
            } catch (ValidationException) {
                self::assertFalse(is_dir($this->directory.'/private'));
            }
        }
    }

    public function test_excessive_dimensions_or_pixel_count_are_rejected_before_any_write(): void
    {
        $png = base64_decode($this->pngBase64(), true);
        foreach ([[12001, 1], [7000, 6000]] as [$width, $height]) {
            $bytes = substr_replace($png, pack('NN', $width, $height), 16, 8);
            try {
                $this->assets->validate($this->document([$this->asset(bytes: $bytes)]), [$this->url()]);
                self::fail('A small encoded header must not permit an enormous decoded image.');
            } catch (ValidationException) {
                self::assertFalse(is_dir($this->directory.'/private'));
            }
        }
    }

    public function test_valid_bundle_flows_through_private_cache_and_existing_downloader_without_http(): void
    {
        $supplier = $this->supplier();
        $product = $this->product($supplier);
        $url = str_replace('.png', '.webp', $this->url());
        $bytes = base64_decode($this->pngBase64(), true);
        $valid = $this->assets->validate($this->document([$this->asset($url)]), [$url]);
        $this->assets->store($supplier, $valid);
        self::assertTrue(is_file($this->directory.'/private/assets/'.hash('sha256', $url).'.image'));
        $path = $this->downloader()->storeFixture($product, $url);
        self::assertNotNull($path);
        self::assertStringEndsWith('.png', $path);
        self::assertSame($bytes, Storage::disk('public')->get($path));
        DB::table('product_images')->insert(['product_id' => $product->id, 'path' => $path]);
        Storage::disk('public')->put('old-extra.png', $bytes.'old');
        DB::table('product_images')->insert(['product_id' => $product->id, 'path' => 'old-extra.png']);
        $before = DB::table('product_images')->get()->toArray();
        self::assertTrue($this->assets->verify($product, $supplier, [$url]));
        self::assertEquals($before, DB::table('product_images')->get()->toArray(), 'Verification does not prune existing photos.');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_verification_requires_matching_public_file_bytes_without_mutating_rows(): void
    {
        $supplier = $this->supplier();
        $product = $this->product($supplier);
        $this->assets->store($supplier, [$this->url() => base64_decode($this->pngBase64(), true)]);
        Storage::disk('public')->put('wrong.png', '<html>not the photo</html>');
        DB::table('product_images')->insert(['product_id' => $product->id, 'path' => 'wrong.png']);
        self::assertFalse($this->assets->verify($product, $supplier, [$this->url()]));
        self::assertSame(1, DB::table('product_images')->count());
        Http::assertNothingSent();
    }

    public function test_cache_storage_is_restricted_to_the_alta_supplier_and_revalidates_bytes(): void
    {
        $supplier = $this->supplier(false);
        self::assertFalse($this->assets->verify($this->product($supplier), $supplier, [$this->url()]));
        try {
            $this->assets->store($supplier, [$this->url() => base64_decode($this->pngBase64(), true)]);
            self::fail('Other supplier drivers cannot write this private Alta cache.');
        } catch (ValidationException) {
            self::assertFalse(is_dir($this->directory.'/private'));
        }
        $supplier->update(['code' => 'alta', 'driver' => AltaDriver::class]);
        $this->expectException(ValidationException::class);
        $this->assets->store($supplier, [$this->url() => '<html>not a photo</html>']);
    }

    public function test_alta_http_200_html_is_not_saved_as_a_product_photo(): void
    {
        $product = $this->product($this->supplier());
        Http::fake([$this->url() => Http::response('<html>Access denied</html>', 200, ['Content-Type' => 'text/html'])]);
        self::assertNull($this->downloader()->storeFixture($product, $this->url()));
        self::assertSame([], Storage::disk('public')->allFiles());
        Http::assertSentCount(1);
    }

    public function test_alta_http_raster_uses_actual_bytes_to_determine_the_stored_format(): void
    {
        $product = $this->product($this->supplier());
        $url = str_replace('.png', '.webp', $this->url());
        $bytes = base64_decode($this->pngBase64(), true);
        Http::fake([$url => Http::response($bytes, 200, ['Content-Type' => 'text/html'])]);
        $path = $this->downloader()->storeFixture($product, $url);
        self::assertNotNull($path);
        self::assertStringEndsWith('.png', $path);
        self::assertSame($bytes, Storage::disk('public')->get($path));
        Http::assertSentCount(1);
    }

    public function test_an_old_invalid_public_file_is_not_reused_as_a_valid_alta_photo(): void
    {
        $product = $this->product($this->supplier());
        $path = 'products/'.$product->id.'/alta-001234-01-'.substr(sha1($this->url()), 0, 12).'.png';
        Storage::disk('public')->put($path, '<html>old invalid download</html>');
        self::assertNull($this->downloader()->storeFixture($product, $this->url()));
        self::assertSame('<html>old invalid download</html>', Storage::disk('public')->get($path), 'Rejecting a file does not delete prior product data.');
        Http::assertNothingSent();
    }

    public function test_old_private_cache_entries_with_excessive_dimensions_are_not_copied_publicly(): void
    {
        $product = $this->product($this->supplier());
        $bytes = substr_replace(base64_decode($this->pngBase64(), true), pack('NN', 12001, 1), 16, 8);
        AltaImageCache::store([$this->url() => $bytes], $this->directory.'/private');
        self::assertNull($this->downloader()->storeFixture($product, $this->url()));
        self::assertSame([], Storage::disk('public')->allFiles());
        Http::assertNothingSent();
    }
}
