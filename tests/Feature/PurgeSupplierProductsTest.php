<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOffer;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Taking a supplier's products back out of the catalogue.
 *
 * This deletes for good, so the cases that must not go wrong are the ones
 * tested: a product two suppliers sell is not one supplier's to remove, and a
 * product somebody has ordered is part of their receipt.
 */
class PurgeSupplierProductsTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $elite;

    protected Supplier $other;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->elite = $this->supplier('elite', 1);
        $this->other = $this->supplier('zoommer', 2);

        $this->category = Category::create(['is_active' => true, 'sort_order' => 1]);
        $this->category->saveTranslations(['ka' => ['name' => 'კატეგორია', 'slug' => 'cat']]);
    }

    /** Without --force it is a report and nothing else. */
    public function test_a_dry_run_deletes_nothing(): void
    {
        $product = $this->product('A', [$this->elite]);

        $this->artisan('products:purge elite')
            ->expectsOutputToContain('Nothing was deleted')
            ->assertSuccessful();

        $this->assertNotNull($product->fresh());
    }

    public function test_it_deletes_the_products_only_that_supplier_sells(): void
    {
        $mine = $this->product('A', [$this->elite]);

        $this->artisan('products:purge elite --force')->assertSuccessful();

        $this->assertNull(Product::withTrashed()->find($mine->id));
    }

    /**
     * The children are declared cascadeOnDelete, which a soft delete never
     * triggers — so "deleted" products went on being counted by every facet.
     */
    public function test_everything_attached_goes_with_it(): void
    {
        $product = $this->product('A', [$this->elite]);

        ProductImage::create(['product_id' => $product->id, 'sort_order' => 0, 'path' => 'products/x.jpg']);

        $this->assertSame(1, DB::table('product_translations')->where('product_id', $product->id)->count());
        $this->assertSame(1, DB::table('product_images')->where('product_id', $product->id)->count());
        $this->assertSame(1, DB::table('product_offers')->where('product_id', $product->id)->count());

        $this->artisan('products:purge elite --force')->assertSuccessful();

        foreach (['product_translations', 'product_images', 'product_offers', 'product_specs'] as $table) {
            $this->assertSame(0, DB::table($table)->where('product_id', $product->id)->count(),
                "{$table} still holds rows for a product that was deleted");
        }
    }

    /** A product someone else also sells loses the offer, not its life. */
    public function test_a_shared_product_survives_and_is_repriced(): void
    {
        $shared = $this->product('B', [$this->elite, $this->other]);

        $this->artisan('products:purge elite --force')->assertSuccessful();

        $this->assertNotNull($shared->fresh(), 'a product another supplier sells must not be deleted');
        $this->assertSame(0, ProductOffer::where('product_id', $shared->id)
            ->where('supplier_id', $this->elite->id)->count());
        $this->assertSame(1, ProductOffer::where('product_id', $shared->id)->count());
    }

    /** An order keeps its own copy of the name and price, but not the product. */
    public function test_an_ordered_product_is_kept_by_default(): void
    {
        $ordered = $this->product('C', [$this->elite]);
        $this->orderItemFor($ordered);

        $this->artisan('products:purge elite')
            ->expectsOutputToContain('--include-ordered')
            ->assertSuccessful();

        $this->artisan('products:purge elite --force')->assertSuccessful();

        $this->assertNotNull($ordered->fresh(), 'an ordered product must not be deleted without being asked');
    }

    public function test_include_ordered_deletes_it_and_leaves_the_order(): void
    {
        $ordered = $this->product('C', [$this->elite]);
        $itemId = $this->orderItemFor($ordered);

        $this->artisan('products:purge elite --force --include-ordered')->assertSuccessful();

        $this->assertNull(Product::withTrashed()->find($ordered->id));

        $item = DB::table('order_items')->find($itemId);
        $this->assertNotNull($item, 'the order line must survive');
        $this->assertNull($item->product_id);
        $this->assertSame('SKU-C', $item->sku);
    }

    public function test_the_status_filter_narrows_what_is_deleted(): void
    {
        $draft = $this->product('D', [$this->elite]);
        $live = $this->product('E', [$this->elite], Product::STATUS_ACTIVE);

        $this->artisan('products:purge elite --force --status=draft')->assertSuccessful();

        $this->assertNull(Product::withTrashed()->find($draft->id));
        $this->assertNotNull($live->fresh(), 'an active product must survive --status=draft');
    }

    public function test_an_unknown_supplier_fails(): void
    {
        $this->artisan('products:purge nobody')->assertFailed();
    }

    /* ------------------------------------------------------------------ helpers */

    protected function supplier(string $code, int $priority): Supplier
    {
        return Supplier::create([
            'code' => $code, 'name' => ucfirst($code), 'driver' => 'X', 'is_active' => true,
            'priority' => $priority, 'markup' => [['percent' => 10]],
        ]);
    }

    /** @param  array<int, Supplier>  $suppliers */
    protected function product(string $key, array $suppliers, string $status = Product::STATUS_DRAFT): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.$key, 'category_id' => $this->category->id,
            'price' => 100, 'stock' => 1, 'status' => $status,
        ]);
        $product->saveTranslations(['ka' => ['name' => 'პროდუქტი '.$key, 'slug' => strtolower($key)]]);

        foreach ($suppliers as $i => $supplier) {
            ProductOffer::create([
                'product_id' => $product->id, 'supplier_id' => $supplier->id,
                'external_id' => $key.'-'.$i, 'cost_price' => 80, 'stock' => 1, 'synced_at' => now(),
            ]);
        }

        return $product;
    }

    protected function orderItemFor(Product $product): int
    {
        $orderId = DB::table('orders')->insertGetId([
            'number' => 'IAPI-TEST-'.$product->id,
            'name' => 'ტესტი', 'phone' => '555', 'email' => 'a@b.ge',
            'address' => 'მისამართი', 'payment' => 'transfer', 'status' => 'new',
            'subtotal' => 100, 'shipping' => 0, 'total' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('order_items')->insertGetId([
            'order_id' => $orderId, 'product_id' => $product->id,
            'sku' => $product->sku, 'name' => 'პროდუქტი', 'price' => 100, 'qty' => 1, 'sum' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
