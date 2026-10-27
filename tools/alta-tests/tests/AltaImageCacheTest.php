<?php

declare(strict_types=1);

namespace AltaTests;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaImageCache;
use App\Services\Import\ImageDownloader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class AltaImageCacheTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = dirname(__DIR__).'/storage/images-'.bin2hex(random_bytes(6));
        config(['services.alta.retail_directory' => $this->directory.'/private',
            'filesystems.default' => 'public', 'filesystems.disks.public' => [
                'driver' => 'local', 'root' => $this->directory.'/public', 'throw' => true,
            ]]);
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeFixtureDirectory($this->directory);
        parent::tearDown();
    }

    protected function downloader(): ImageDownloader
    {
        return new class extends ImageDownloader {
            public function storeFixture(Product $product, string $url): ?string
            {
                return parent::store($product, $url, 0);
            }
        };
    }

    protected function product(bool $alta): Product
    {
        $supplier = Supplier::create(['code' => $alta ? 'alta' : 'other', 'name' => 'Synthetic supplier',
            'driver' => $alta ? AltaDriver::class : 'SyntheticOtherDriver', 'config' => [], 'markup' => [], 'is_active' => true]);
        DB::table('products')->insert(['id' => 16, 'sku' => 'synthetic-001234', 'price' => 799, 'stock' => 3]);
        DB::table('product_offers')->insert(['product_id' => 16, 'supplier_id' => $supplier->id,
            'external_id' => '001234', 'cost_price' => 799, 'stock' => 3]);

        return Product::findOrFail(16);
    }

    public function test_alta_private_image_bytes_are_used_without_any_server_http_request(): void
    {
        $url = 'https://imgstore.alta.ge/images/first.webp';
        $bytes = base64_decode($this->pngBase64(), true);
        $valid = AltaImageCache::validate([['url' => $url, 'base64' => $this->pngBase64()]], [$url]);
        AltaImageCache::store($valid, $this->directory.'/private');
        $path = $this->downloader()->storeFixture($this->product(true), $url);

        self::assertNotNull($path);
        self::assertStringEndsWith('.png', $path, 'Actual image format determines the stored extension.');
        self::assertSame($bytes, Storage::disk('public')->get($path));
        Http::assertNothingSent();
    }

    public function test_generic_supplier_image_download_keeps_its_existing_http_path(): void
    {
        $url = 'https://generic.invalid/image.png';
        $bytes = base64_decode($this->pngBase64(), true);
        Http::fake([$url => Http::response($bytes, 200, ['Content-Type' => 'image/png'])]);
        $path = $this->downloader()->storeFixture($this->product(false), $url);

        self::assertNotNull($path);
        self::assertSame($bytes, Storage::disk('public')->get($path));
        Http::assertSentCount(1);
    }

    public function test_expired_private_asset_is_not_reused(): void
    {
        $url = 'https://imgstore.alta.ge/images/first.webp';
        AltaImageCache::store([$url => base64_decode($this->pngBase64(), true)], $this->directory.'/private');
        $path = $this->directory.'/private/assets/'.hash('sha256', $url).'.image';
        touch($path, time() - 7200);
        clearstatcache(true, $path);

        self::assertNull(AltaImageCache::read($this->directory.'/private', $url, 3600));
        Http::assertNothingSent();
    }

    public function test_oversized_raster_asset_is_rejected_before_store(): void
    {
        $url = 'https://imgstore.alta.ge/images/first.webp';
        $bytes = base64_decode($this->pngBase64(), true).str_repeat('x', 1048576);
        $this->expectException(ValidationException::class);
        AltaImageCache::validate([['url' => $url, 'base64' => base64_encode($bytes)]], [$url]);
    }

    public function test_enough_old_image_rows_do_not_verify_or_get_pruned_when_current_bytes_are_missing(): void
    {
        $product = $this->product(true);
        $urls = ['https://imgstore.alta.ge/images/current-a.webp', 'https://imgstore.alta.ge/images/current-b.webp'];
        $png = base64_decode($this->pngBase64(), true);
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        AltaImageCache::store([$urls[0] => $png, $urls[1] => $gif], $this->directory.'/private');
        foreach (['old-a.png', 'old-b.png'] as $index => $path) {
            Storage::disk('public')->put($path, $png.'old-'.$index);
            DB::table('product_images')->insert(['product_id' => $product->id, 'path' => $path, 'sort_order' => $index]);
        }
        $before = DB::table('product_images')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        self::assertFalse(AltaImageCache::verifyProduct($product, $urls, $this->directory.'/private'));
        self::assertFalse(AltaImageCache::verifyProduct($product, $urls, $this->directory.'/private', prune: true));
        self::assertSame($before, DB::table('product_images')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), 'An incomplete update must preserve all prior image rows.');
        self::assertTrue(Storage::disk('public')->exists('old-a.png'));
        self::assertTrue(Storage::disk('public')->exists('old-b.png'));
        Http::assertNothingSent();
    }

    public function test_complete_current_bytes_allow_pruning_only_old_extra_rows_and_leave_physical_files(): void
    {
        $product = $this->product(true);
        $urls = ['https://imgstore.alta.ge/images/current-a.webp', 'https://imgstore.alta.ge/images/current-b.webp'];
        $png = base64_decode($this->pngBase64(), true);
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        AltaImageCache::store([$urls[0] => $png, $urls[1] => $gif], $this->directory.'/private');
        foreach (['current-a.png' => $png, 'current-b.gif' => $gif, 'old-extra.png' => $png.'old-extra'] as $path => $bytes) {
            Storage::disk('public')->put($path, $bytes);
            DB::table('product_images')->insert(['product_id' => $product->id, 'path' => $path]);
        }

        self::assertTrue(AltaImageCache::verifyProduct($product, $urls, $this->directory.'/private'));
        self::assertSame(3, DB::table('product_images')->count(), 'Verification alone does not prune.');
        self::assertTrue(AltaImageCache::verifyProduct($product, $urls, $this->directory.'/private', prune: true));
        self::assertSame(['current-a.png', 'current-b.gif'], DB::table('product_images')->orderBy('id')->pluck('path')->all());
        self::assertTrue(Storage::disk('public')->exists('old-extra.png'), 'Pruning only changes obsolete associations.');
        self::assertSame($png, Storage::disk('public')->get('current-a.png'));
        self::assertSame($gif, Storage::disk('public')->get('current-b.gif'));
        Http::assertNothingSent();
    }
}
