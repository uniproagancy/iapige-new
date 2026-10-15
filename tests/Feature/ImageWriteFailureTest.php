<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Import\ImageDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * A photograph that could not be written was recorded as if it had been.
 *
 * The public disk is configured with 'throw' => false and 'report' => false,
 * so a write it cannot make returns false and says nothing — no exception, no
 * log line. The return value was never read, so every image of every product
 * got a product_images row pointing at a file that was never there.
 *
 * Which was invisible from the admin: the "no photo" filter counts rows, so a
 * product whose pictures all failed looked exactly like one with pictures. The
 * shop served broken images and nothing had complained. A directory the web
 * user cannot write to is the usual cause, and it fails for every product at
 * once.
 */
class ImageWriteFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_photograph_that_was_written_is_recorded(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('a picture', 200)]);

        $product = $this->product();

        app(ImageDownloader::class)->sync($product, ['https://www.midea.ge/uploads/products/a.jpg']);

        $this->assertSame(1, ProductImage::where('product_id', $product->id)->count());

        Storage::disk('public')->assertExists(ProductImage::where('product_id', $product->id)->value('path'));
    }

    /** A write that fails records nothing, so the product reads as having no photo. */
    public function test_a_photograph_that_could_not_be_written_is_not_recorded(): void
    {
        $this->fakeUnwritableDisk();
        Http::fake(['*' => Http::response('a picture', 200)]);

        $product = $this->product();

        app(ImageDownloader::class)->sync($product, ['https://www.midea.ge/uploads/products/a.jpg']);

        $this->assertSame(0, ProductImage::where('product_id', $product->id)->count());
    }

    /** A whole gallery onto a disk that cannot be written leaves nothing behind. */
    public function test_a_whole_gallery_onto_an_unwritable_disk_records_nothing(): void
    {
        $this->fakeUnwritableDisk();
        Http::fake(['*' => Http::response('a picture', 200)]);

        $product = $this->product();

        app(ImageDownloader::class)->sync($product, [
            'https://www.midea.ge/uploads/products/a.jpg',
            'https://www.midea.ge/uploads/products/b.jpg',
            'https://www.midea.ge/uploads/products/c.jpg',
        ]);

        $this->assertSame(0, ProductImage::where('product_id', $product->id)->count());
    }

    /**
     * And the importer asks again next time.
     *
     * Recording the row was what made the failure permanent: import() only
     * re-fetches when the product has fewer images than the payload offers, so
     * a product with three rows and no files was never asked for again.
     */
    public function test_the_next_run_tries_again(): void
    {
        $this->fakeUnwritableDisk();
        Http::fake(['*' => Http::response('a picture', 200)]);

        $product = $this->product();
        $urls = ['https://www.midea.ge/uploads/products/a.jpg'];

        app(ImageDownloader::class)->sync($product, $urls);

        $this->assertSame(0, $product->images()->count());

        // the disk comes back
        Storage::fake('public');

        app(ImageDownloader::class)->sync($product->fresh(), $urls);

        $this->assertSame(1, $product->fresh()->images()->count());
    }

    /* ------------------------------------------------------------------ odd addresses */

    /**
     * A filename with spaces in it.
     *
     * Media is whatever somebody saved it as, and "Screenshot 2026-10-09
     * 173441.png" is a real one. cURL refuses such a URL outright —
     * "Malformed input to a URL function" — so the picture was never fetched,
     * the product arrived with none, and nothing in the log named the cause.
     */
    public function test_an_address_with_spaces_is_fetched(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('a picture', 200)]);

        $product = $this->product();

        app(ImageDownloader::class)->sync($product, [
            'https://media.citrus.ge/images/products/CS406234/Screenshot 2026-10-09 173441.png',
        ]);

        $this->assertSame(1, ProductImage::where('product_id', $product->id)->count());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'Screenshot%202026-10-09%20173441.png'));
    }

    /** An address that was already escaped is left as it was. */
    public function test_an_already_encoded_address_is_not_encoded_twice(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('a picture', 200)]);

        app(ImageDownloader::class)->sync($this->product(), [
            'https://media.citrus.ge/images/products/CS406234/Screenshot%202026-10-09.png',
        ]);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '%25'));
    }

    /** A query string is not a path and must survive untouched. */
    public function test_a_query_string_survives(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('a picture', 200)]);

        app(ImageDownloader::class)->sync($this->product(), [
            'https://cdn.example.com/image.jpg?w=800&h=600',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '?w=800&h=600'));
    }
    /* ------------------------------------------------------------------ helpers */

    /**
     * A disk that accepts everything and writes nothing.
     *
     * Which is exactly what the real one does when it cannot write: 'throw'
     * is false, so put() returns false rather than raising anything.
     */
    protected function fakeUnwritableDisk(): void
    {
        $disk = Storage::fake('public');

        $refusing = Mockery::mock($disk)->makePartial();
        $refusing->shouldReceive('put')->andReturn(false);

        Storage::set('public', $refusing);
    }

    protected function product(): Product
    {
        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        return Product::create([
            'sku' => 'midea-6939924857572',
            'category_id' => $category->id,
            'price' => 149,
            'stock' => 4,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);
    }
}
