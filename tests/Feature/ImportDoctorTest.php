<?php

namespace Tests\Feature;

use App\Console\Commands\ImportDoctor;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * "Why did it only import fifty?"
 *
 * The question has come up for nearly every supplier, and answering it has
 * meant three separate guesses: whether the file loaded, whether the limits
 * are cutting it, whether the queue is still working. Each is a different
 * query and none of them is obvious, so the answer has usually been a guess
 * followed by a surprise.
 *
 * The funnel counts every step instead, and the step where the number
 * collapses is the answer.
 */
class ImportDoctorTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ the funnel */

    public function test_it_counts_the_rows_in_the_price_list(): void
    {
        $this->row('A-1', quantity: 9, sale: 80);
        $this->row('A-2', quantity: 9, sale: 80);

        $this->artisan('import:doctor ingco')
            ->expectsOutputToContain('rows in the price list')
            ->assertSuccessful();
    }

    public function test_an_empty_price_list_says_so(): void
    {
        $this->artisan('import:doctor ingco')
            ->expectsOutputToContain('No price list loaded')
            ->assertSuccessful();
    }

    /**
     * The limits are counted against the price we pay.
     *
     * A list carrying two figures has the promotional one in data.sale, and
     * measuring a floor against the list price beside it lets a product
     * through on a number nobody was charged.
     */
    public function test_the_price_floor_is_measured_against_what_we_pay(): void
    {
        $this->row('CHEAP', quantity: 9, sale: 20, cost: 60);
        $this->row('DEAR', quantity: 9, sale: 80, cost: 96);

        $this->artisan('import:doctor ingco')->assertSuccessful();

        // the cheap row's list price clears the floor; its own does not
        $this->assertSame(1, $this->clearingBoth());
    }

    public function test_the_stock_floor_is_counted(): void
    {
        $this->row('A-1', quantity: 1, sale: 80);
        $this->row('A-2', quantity: 5, sale: 80);

        $this->assertSame(1, $this->clearingBoth());
    }

    /** What clears every rule and still has no product is the supplier's own doing. */
    public function test_it_names_the_gap_nothing_else_explains(): void
    {
        $this->row('A-1', quantity: 9, sale: 80);
        $this->row('A-2', quantity: 9, sale: 80);

        $this->artisan('import:doctor ingco')
            ->expectsOutputToContain('clear every rule and have no product')
            ->assertSuccessful();
    }

    public function test_no_gap_is_reported_once_the_products_exist(): void
    {
        $this->row('A-1', quantity: 9, sale: 80);

        $this->offer('A-1');

        $this->artisan('import:doctor ingco')
            ->doesntExpectOutputToContain('clear every rule and have no product')
            ->assertSuccessful();
    }

    /* ------------------------------------------------------------------ settings */

    /** An unmapped stock column is the fault that hides best. */
    public function test_an_unmapped_stock_column_is_called_out(): void
    {
        $this->supplier->update(['config' => ['columns' => ['key' => 'A']]]);

        $this->artisan('import:doctor ingco')
            ->expectsOutputToContain('not mapped')
            ->assertSuccessful();
    }

    /* ------------------------------------------------------------------ the sample */

    /**
     * The hit rate is measured against the real driver, not guessed.
     *
     * Every other number this command reports counts rows and rules. None of
     * them can say whether the supplier's own source will describe a row when
     * asked — and for a supplier whose catalogue is larger than its website,
     * that is the figure that decides everything.
     */
    public function test_a_sample_reports_how_many_the_source_describes(): void
    {
        $this->supplier->update(['driver' => HalfAnsweringDriver::class]);

        $this->row('A-1', quantity: 9, sale: 80);
        $this->row('A-2', quantity: 9, sale: 80);
        $this->row('A-3', quantity: 9, sale: 80);
        $this->row('A-4', quantity: 9, sale: 80);

        $this->artisan('import:doctor ingco --sample=4')
            ->expectsOutputToContain('the source described it')
            ->expectsOutputToContain('of rows produce a product')
            ->assertSuccessful();
    }

    /** A row with nothing in stock is not worth spending a request on. */
    public function test_a_sample_leaves_empty_rows_out(): void
    {
        $this->supplier->update(['driver' => HalfAnsweringDriver::class]);

        $this->row('A-1', quantity: 0, sale: 80);

        $this->artisan('import:doctor ingco --sample=4')
            ->doesntExpectOutputToContain('a real sample')
            ->assertSuccessful();
    }

    /** A driver that throws is counted, not allowed to stop the report. */
    public function test_a_driver_that_throws_is_counted(): void
    {
        $this->supplier->update(['driver' => ThrowingDriver::class]);

        $this->row('A-1', quantity: 9, sale: 80);

        $this->artisan('import:doctor ingco --sample=1')
            ->expectsOutputToContain('it threw')
            ->assertSuccessful();
    }

    /** Without the option nothing is fetched at all. */
    public function test_no_sample_is_taken_unless_asked_for(): void
    {
        $this->supplier->update(['driver' => ThrowingDriver::class]);

        $this->row('A-1', quantity: 9, sale: 80);

        $this->artisan('import:doctor ingco')
            ->doesntExpectOutputToContain('a real sample')
            ->assertSuccessful();
    }

    /**
     * A setting the row simply does not carry is named.
     *
     * The seeder only runs on a fresh database, so the config a supplier
     * actually holds drifts from the one the code was written against — and
     * every time it has, the symptom showed up somewhere else entirely.
     */
    public function test_a_missing_setting_is_named_with_its_consequence(): void
    {
        $this->supplier->update(['config' => ['columns' => ['key' => 'A']]]);

        $output = $this->report('ingco');

        $this->assertStringContainsString('missing columns.stock', $output);
        $this->assertStringContainsString('every row counts as one available', $output);
    }

    /**
     * read_site off is the one that hides best.
     *
     * The driver reads it with `?? true`, so a row that never gained the key
     * behaves one way and a row that has it set to false behaves another, and
     * nothing anywhere says which it was. Off, every Midea product arrives
     * without a photograph and with a name assembled out of the spreadsheet.
     */
    public function test_read_site_being_off_is_called_out(): void
    {
        $this->supplier->update([
            'driver' => 'App\Services\Import\Drivers\Midea\MideaDriver',
            'config' => ['columns' => ['key' => 'A', 'stock' => 'D', 'model' => 'B'], 'listings' => ['/x'], 'read_site' => false],
        ]);

        $this->artisan('import:doctor ingco')
            ->expectsOutputToContain('read_site is off')
            ->assertSuccessful();
    }

    public function test_the_whole_config_is_printed(): void
    {
        $this->artisan('import:doctor ingco')
            ->expectsOutputToContain('full config:')
            ->assertSuccessful();
    }

    public function test_an_unknown_supplier_fails(): void
    {
        $this->artisan('import:doctor nobody')->assertFailed();
    }

    /* ------------------------------------------------------------------ helpers */
    /**
     * The whole report as text.
     *
     * Read back rather than asserted line by line, because two expectations
     * chained onto one artisan() call do not both survive — and a report is
     * read as a whole anyway.
     */
    protected function report(string $supplier): string
    {
        Artisan::call('import:doctor', ['supplier' => $supplier]);

        return Artisan::output();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->supplier = Supplier::create([
            'code' => 'ingco',
            'name' => 'INGCO',
            'driver' => 'X',
            'is_active' => true,
            'priority' => 60,
            'markup' => [['percent' => 20]],
            'config' => [
                'columns' => ['key' => 'A', 'sale' => 'B', 'cost' => 'C', 'stock' => 'D'],
                'min_price' => 50,
                'min_stock' => 2,
            ],
        ]);
    }

    /**
     * How many rows clear every rule, asked of the command itself.
     *
     * Through its own protected method rather than by parsing its table: the
     * table is for a person and its shape is allowed to change.
     */
    protected function clearingBoth(): int
    {
        $command = new ImportDoctor;
        $method = new \ReflectionMethod($command, 'countByPrice');
        $method->setAccessible(true);

        $rows = SupplierStock::where('supplier_id', $this->supplier->id)->count();
        $clearStock = SupplierStock::where('supplier_id', $this->supplier->id)->where('quantity', '>=', 2)->count();

        return $method->invoke($command, $this->supplier, 50.0, 2, $rows, $clearStock)[1];
    }

    protected function row(string $id, int $quantity, float $sale, ?float $cost = null): SupplierStock
    {
        return SupplierStock::create([
            'supplier_id' => $this->supplier->id,
            'external_id' => $id,
            'quantity' => $quantity,
            'cost_price' => $cost ?? $sale * 1.2,
            'data' => ['sale' => (string) $sale],
            'synced_at' => now(),
        ]);
    }

    protected function offer(string $externalId): ProductOffer
    {
        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        $product = Product::create([
            'sku' => 'ingco-'.$externalId,
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 9,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        return ProductOffer::create([
            'product_id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'external_id' => $externalId,
            'cost_price' => 80,
            'stock' => 9,
            'synced_at' => now(),
        ]);
    }
}

/** Answers for half of what it is asked, like a site smaller than its warehouse. */
class HalfAnsweringDriver implements SupplierDriver
{
    public function __construct(public Supplier $supplier) {}

    public function ids(): iterable
    {
        return [];
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        return in_array($externalId, ['A-1', 'A-2'], true)
            ? new ProductPayload(
                externalId: $externalId,
                sku: 'ingco-'.$externalId,
                costPrice: 80,
                oldCostPrice: null,
                stock: 9,
                brandName: 'INGCO',
                categoryName: null,
                translations: ['ka' => ['name' => 'ხელსაწყო']],
            )
            : null;
    }
}

class ThrowingDriver implements SupplierDriver
{
    public function __construct(public Supplier $supplier) {}

    public function ids(): iterable
    {
        return [];
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        throw new \RuntimeException('the source refused');
    }
}
