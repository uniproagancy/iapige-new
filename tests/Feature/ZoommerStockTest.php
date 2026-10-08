<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\Drivers\Zoommer\ZoommerDriver;
use App\Services\Import\ImageDownloader;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Only what a customer can actually collect in Tbilisi gets added.
 *
 * The source lists its whole catalogue, most of which sits in another city or
 * nowhere at all. Those were imported too: the stock came through as zero, as
 * it should, but the product was created anyway — a page nobody can buy from,
 * with photographs downloaded for it and a category waiting to be mapped by
 * hand.
 */
class ZoommerStockTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ driver */

    public function test_a_tbilisi_branch_with_stock_counts(): void
    {
        $this->assertSame(5, $this->stockFor([
            ['city' => 'თბილისი', 'inStock' => true],
        ]));
    }

    /**
     * A branch names itself, not just its city.
     *
     * "თბილისი, ვაჟა-ფშაველა" was read as a different town by an exact match,
     * so a product on the shelf came through as out of stock.
     */
    public function test_a_branch_name_after_the_city_still_counts(): void
    {
        $this->assertSame(5, $this->stockFor([
            ['city' => 'თბილისი, ვაჟა-ფშაველა', 'inStock' => true],
        ]));
    }

    public function test_another_city_does_not_count(): void
    {
        $this->assertSame(0, $this->stockFor([
            ['city' => 'ბათუმი', 'inStock' => true],
            ['city' => 'ქუთაისი', 'inStock' => true],
        ]));
    }

    /** Listed in Tbilisi but with nothing on the shelf is still nothing. */
    public function test_a_tbilisi_branch_out_of_stock_does_not_count(): void
    {
        $this->assertSame(0, $this->stockFor([
            ['city' => 'თბილისი', 'inStock' => false],
        ]));
    }

    public function test_no_branches_at_all_is_no_stock(): void
    {
        $this->assertSame(0, $this->stockFor([]));
    }

    /** The product's own flag is checked before any branch is. */
    public function test_a_product_the_source_calls_out_of_stock_counts_for_nothing(): void
    {
        $this->assertSame(0, $this->stockFor([['city' => 'თბილისი', 'inStock' => true]], inStock: false));
    }

    /* ------------------------------------------------------------------ importer */

    /** The whole point: an unobtainable product never enters the catalogue. */
    public function test_a_new_product_with_no_stock_is_not_created(): void
    {
        app(ProductImporter::class)->import($this->supplier, $this->payload('ZM-1', stock: 0));

        $this->assertSame(0, Product::count());
        $this->assertDatabaseCount('product_offers', 0);
    }

    public function test_a_new_product_with_stock_is_created(): void
    {
        app(ProductImporter::class)->import($this->supplier, $this->payload('ZM-1', stock: 5));

        $this->assertSame(1, Product::count());
    }

    /**
     * Running out is not the same as never existing.
     *
     * A product already in the catalogue goes to zero and stays, because
     * orders point at it and somebody may have edited it by hand.
     */
    public function test_an_existing_product_is_kept_when_it_runs_out(): void
    {
        $importer = app(ProductImporter::class);

        $importer->import($this->supplier, $this->payload('ZM-1', stock: 5));
        $importer->import($this->supplier, $this->payload('ZM-1', stock: 0));

        $this->assertSame(1, Product::count());
        $this->assertSame(0, Product::first()->stock);
    }

    /** Off unless asked for: a supplier with no stock figures would import nothing. */
    public function test_without_the_flag_a_product_with_no_stock_is_still_added(): void
    {
        $this->supplier->update(['config' => ['require_stock' => false]]);

        app(ProductImporter::class)->import($this->supplier->fresh(), $this->payload('ZM-1', stock: 0));

        $this->assertSame(1, Product::count());
    }

    /* ------------------------------------------------------------------ helpers */

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->supplier = Supplier::create([
            'code' => 'zoommer', 'name' => 'Zoommer', 'driver' => ZoommerDriver::class, 'is_active' => true,
            'priority' => 10, 'markup' => [['up_to' => 5000, 'percent' => 20]],
            'config' => ['require_stock' => true],
        ]);

        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => 'ტელეფონები', 'slug' => 'phones']]);

        $this->instance(ImageDownloader::class, new class extends ImageDownloader
        {
            public function sync(Product $product, array $urls, int $limit = self::MAX_IMAGES): void {}
        });
    }

    /** @param  array<int, array<string, mixed>>  $stores */
    protected function stockFor(array $stores, bool $inStock = true): int
    {
        $driver = new ZoommerDriver(new Supplier([
            'code' => 'zoommer',
            'driver' => ZoommerDriver::class,
            'config' => ['stock_cities' => ['თბილისი', 'tbilisi'], 'stock_units' => 5],
        ]));

        $method = new \ReflectionMethod($driver, 'stock');
        $method->setAccessible(true);

        return $method->invoke($driver, [
            'product' => ['isInStock' => $inStock],
            'availabilityInStores' => $stores,
        ]);
    }

    protected function payload(string $externalId, int $stock): ProductPayload
    {
        return new ProductPayload(
            externalId: $externalId,
            sku: '6931474700000',
            costPrice: 1000,
            oldCostPrice: null,
            stock: $stock,
            brandName: 'Lenovo',
            categoryName: 'Phones',
            translations: ['ka' => ['name' => 'ტელეფონი']],
        );
    }
}
