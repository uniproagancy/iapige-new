<?php

namespace Tests\Feature;

use App\Jobs\ImportProductJob;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ImportManager;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A run spent most of its time on things the supplier had run out of.
 *
 * Their file is mostly empty rows, and each one was costing a queued job and a
 * page fetched over the network — to learn what the spreadsheet had already
 * said. On a nine thousand row list where two thirds are empty that is six
 * thousand requests spent on products the shop was never going to sell.
 *
 * The ones we already hold still have to be settled, so what matters is that
 * skipping them does not quietly leave something on sale that is gone.
 */
class ImportSkipsEmptyRowsTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ skipping */

    public function test_an_empty_row_is_not_queued(): void
    {
        Queue::fake();

        $this->row('A-1', 5);
        $this->row('A-2', 0);

        $queued = app(ImportManager::class)->run($this->supplier);

        $this->assertSame(1, $queued);
        Queue::assertPushed(ImportProductJob::class, 1);
    }

    public function test_a_file_of_nothing_but_empty_rows_queues_nothing(): void
    {
        Queue::fake();

        $this->row('A-1', 0);
        $this->row('A-2', 0);

        $this->assertSame(0, app(ImportManager::class)->run($this->supplier));
        Queue::assertNothingPushed();
    }

    /** A negative quantity is a spreadsheet saying the same thing badly. */
    public function test_a_negative_quantity_counts_as_empty(): void
    {
        Queue::fake();

        $this->row('A-1', -3);

        $this->assertSame(0, app(ImportManager::class)->run($this->supplier));
    }

    /**
     * A supplier whose stock is only knowable from its API is untouched.
     *
     * Zoommer and the rest hold no rows here, so there is nothing to read
     * ahead and every id must still be asked about.
     */
    public function test_a_supplier_with_no_price_list_still_queues_everything(): void
    {
        Queue::fake();

        $supplier = Supplier::create([
            'code' => 'api', 'name' => 'API', 'driver' => ApiIdDriver::class,
            'is_active' => true, 'priority' => 1,
        ]);

        $this->assertSame(2, app(ImportManager::class)->run($supplier));
    }

    /* ------------------------------------------------------------------ settling */

    /**
     * What we already sell is taken off sale without fetching anything.
     *
     * This is the half that cannot simply be skipped: the product has a page,
     * and leaving it in stock because its row was skipped would sell something
     * the supplier no longer has.
     */
    public function test_a_product_we_hold_is_taken_off_sale(): void
    {
        Queue::fake();

        $product = $this->product(stock: 7);
        $this->offer($product, 'A-1', 7);
        $this->row('A-1', 0);

        app(ImportManager::class)->run($this->supplier);

        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertSame(0, ProductOffer::where('external_id', 'A-1')->value('stock'));
    }

    /**
     * One supplier running out does not mean the shop has.
     *
     * The product's stock is the sum of its offers, so it is recomputed rather
     * than zeroed — otherwise a product two suppliers carry would vanish the
     * moment the first of them sold out.
     */
    public function test_another_suppliers_stock_keeps_the_product_on_sale(): void
    {
        Queue::fake();

        $other = Supplier::create([
            'code' => 'other', 'name' => 'Other', 'driver' => ApiIdDriver::class,
            'is_active' => true, 'priority' => 2,
            'markup' => [['percent' => 20]],
        ]);

        $product = $this->product(stock: 10);
        $this->offer($product, 'A-1', 4);
        $this->offer($product, 'B-1', 6, $other);
        $this->row('A-1', 0);

        app(ImportManager::class)->run($this->supplier);

        $this->assertSame(6, (int) $product->fresh()->stock);
    }

    public function test_a_row_still_in_stock_is_left_alone(): void
    {
        Queue::fake();

        $product = $this->product(stock: 7);
        $this->offer($product, 'A-1', 7);
        $this->row('A-1', 7);

        app(ImportManager::class)->run($this->supplier);

        $this->assertSame(7, (int) $product->fresh()->stock);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->supplier = Supplier::create([
            'code' => 'sheet', 'name' => 'Sheet', 'driver' => PriceListRowDriver::class,
            'is_active' => true, 'priority' => 1,
            'markup' => [['percent' => 20]],
            'config' => ['columns' => ['key' => 'A', 'stock' => 'B']],
        ]);
    }

    protected function row(string $id, int $quantity): SupplierStock
    {
        return SupplierStock::create([
            'supplier_id' => $this->supplier->id,
            'external_id' => $id,
            'quantity' => $quantity,
            'cost_price' => 100,
            'data' => [],
            'synced_at' => now(),
        ]);
    }

    protected function product(int $stock): Product
    {
        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        return Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $category->id,
            'price' => 120,
            'stock' => $stock,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);
    }

    protected function offer(Product $product, string $externalId, int $stock, ?Supplier $supplier = null): ProductOffer
    {
        return ProductOffer::create([
            'product_id' => $product->id,
            'supplier_id' => ($supplier ?? $this->supplier)->id,
            'external_id' => $externalId,
            'cost_price' => 100,
            'stock' => $stock,
            'synced_at' => now(),
        ]);
    }
}

/** Reads its ids from the price list, like every spreadsheet supplier. */
class PriceListRowDriver implements SupplierDriver
{
    public function __construct(public Supplier $supplier) {}

    public function ids(): iterable
    {
        return SupplierStock::where('supplier_id', $this->supplier->id)
            ->orderBy('external_id')
            ->pluck('external_id');
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        return null;
    }
}

/** Knows its own ids, like an API supplier. */
class ApiIdDriver implements SupplierDriver
{
    public function __construct(public Supplier $supplier) {}

    public function ids(): iterable
    {
        return ['1', '2'];
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        return null;
    }
}
