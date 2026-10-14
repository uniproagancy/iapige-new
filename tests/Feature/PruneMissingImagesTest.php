<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOffer;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Products looked like they had photographs and served broken images.
 *
 * The public disk is configured not to throw, so a write it could not make
 * returned false and said nothing, and the return value was not read — every
 * image got a row pointing at a file that was never written.
 *
 * The rows are the lasting damage, and in a particular way: the importer only
 * re-fetches a gallery when the product has fewer rows than the supplier
 * offers, so a product with three phantom rows and three pictures on offer
 * counts as complete and is never asked about again. Clearing them is what
 * lets the next import fetch the pictures.
 */
class PruneMissingImagesTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->image($this->product(), 'products/1/gone.jpg');

        $this->artisan('images:prune')
            ->expectsOutputToContain('Nothing was deleted')
            ->assertSuccessful();

        $this->assertSame(1, ProductImage::count());
    }

    public function test_a_row_with_no_file_is_removed(): void
    {
        $this->image($this->product(), 'products/1/gone.jpg');

        $this->artisan('images:prune --force')->assertSuccessful();

        $this->assertSame(0, ProductImage::count());
    }

    /** A photograph that is really there is somebody's, and stays. */
    public function test_a_row_whose_file_exists_is_kept(): void
    {
        Storage::disk('public')->put('products/1/real.jpg', 'a picture');

        $this->image($this->product(), 'products/1/real.jpg');

        $this->artisan('images:prune --force')
            ->expectsOutputToContain('Every recorded photograph is on disk')
            ->assertSuccessful();

        $this->assertSame(1, ProductImage::count());
    }

    public function test_only_the_named_supplier_is_touched(): void
    {
        $mine = $this->product();
        $theirs = $this->product();

        $other = Supplier::create([
            'code' => 'alta', 'name' => 'Alta', 'driver' => 'X',
            'is_active' => true, 'priority' => 2,
        ]);

        $this->offer($mine, $this->supplier);
        $this->offer($theirs, $other);

        $this->image($mine, 'products/'.$mine->id.'/gone.jpg');
        $this->image($theirs, 'products/'.$theirs->id.'/gone.jpg');

        $this->artisan('images:prune --supplier=midea --force')->assertSuccessful();

        $this->assertSame(0, ProductImage::where('product_id', $mine->id)->count());
        $this->assertSame(1, ProductImage::where('product_id', $theirs->id)->count());
    }

    public function test_an_unknown_supplier_fails(): void
    {
        $this->artisan('images:prune --supplier=nobody')->assertFailed();
    }

    public function test_nothing_to_check_is_not_an_error(): void
    {
        $this->artisan('images:prune')
            ->expectsOutputToContain('No image rows to check')
            ->assertSuccessful();
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->supplier = Supplier::create([
            'code' => 'midea', 'name' => 'Midea', 'driver' => 'X',
            'is_active' => true, 'priority' => 1,
        ]);
    }

    protected function product(): Product
    {
        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        return Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);
    }

    protected function offer(Product $product, Supplier $supplier): ProductOffer
    {
        return ProductOffer::create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'external_id' => 'EXT-'.$product->id,
            'cost_price' => 80,
            'stock' => 5,
            'synced_at' => now(),
        ]);
    }

    protected function image(Product $product, string $path): ProductImage
    {
        return ProductImage::create([
            'product_id' => $product->id,
            'path' => $path,
            'sort_order' => 0,
        ]);
    }
}
