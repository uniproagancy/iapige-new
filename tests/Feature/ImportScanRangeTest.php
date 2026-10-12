<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Services\Import\Drivers\Elite\EliteDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How much a supplier is asked for.
 *
 * Neither Zoommer nor Elite will say what it has, so the only way in is to
 * ask for ids one at a time — and that was done by counting from one to the
 * end of the range on every run. Thirty-five thousand requests for Elite at
 * the pace the source tolerates is most of a day, nearly all of it spent on
 * ids that have never existed.
 */
class ImportScanRangeTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /** With nothing to build on there is no choice but to walk it all. */
    public function test_the_first_run_walks_the_whole_range(): void
    {
        $this->supplier->update(['config' => ['from' => 1, 'to' => 50]]);

        $this->assertSame(50, count($this->scan()));
    }

    /**
     * Afterwards: what we hold, and a window above it.
     *
     * The ids we hold are the catalogue, and refreshing their prices and
     * stock is what the run is for.
     */
    public function test_a_later_run_asks_for_what_we_hold(): void
    {
        $this->offer('10');
        $this->offer('20');
        $this->offer('30');

        $this->supplier->update(['config' => ['from' => 1, 'to' => 50000, 'discover_ahead' => 5]]);

        $ids = $this->scan();

        $this->assertContains('10', $ids);
        $this->assertContains('20', $ids);
        $this->assertContains('30', $ids);
    }

    /** New products appear above the newest id, so that is where to look. */
    public function test_it_looks_a_little_past_the_newest_id(): void
    {
        $this->offer('30');

        $this->supplier->update(['config' => ['from' => 1, 'to' => 50000, 'discover_ahead' => 5]]);

        $ids = $this->scan();

        $this->assertSame(['30', '31', '32', '33', '34', '35'], $ids);
    }

    /** And the tens of thousands below it are not asked for again. */
    public function test_the_empty_range_is_not_walked_again(): void
    {
        $this->offer('40000');

        $this->supplier->update(['config' => ['from' => 1, 'to' => 50000, 'discover_ahead' => 10]]);

        $this->assertLessThan(20, count($this->scan()), 'a run should not ask for forty thousand ids');
    }

    /**
     * The whole range on request.
     *
     * A product passed over earlier — out of stock the day we asked, say —
     * is only found by a complete sweep, so one is still possible.
     */
    public function test_a_full_scan_walks_everything(): void
    {
        $this->offer('30');

        $this->supplier->update(['config' => ['from' => 1, 'to' => 50, 'full_scan' => true]]);

        $this->assertSame(50, count($this->scan()));
    }

    /** The window is the supplier's to widen. */
    public function test_the_window_can_be_widened(): void
    {
        $this->offer('100');

        $this->supplier->update(['config' => ['from' => 1, 'to' => 50000, 'discover_ahead' => 3]]);
        $this->assertSame(4, count($this->scan()));

        $this->supplier->update(['config' => ['from' => 1, 'to' => 50000, 'discover_ahead' => 50]]);
        $this->assertSame(51, count($this->scan()));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'code' => 'elite', 'name' => 'Elite', 'driver' => EliteDriver::class, 'is_active' => true,
            'priority' => 1, 'markup' => [['up_to' => 5000, 'percent' => 20]],
            'config' => ['from' => 1, 'to' => 50],
        ]);
    }

    protected function offer(string $externalId): void
    {
        $product = Product::create([
            'sku' => 'SKU-'.$externalId, 'price' => 100, 'stock' => 1,
            'status' => Product::STATUS_DRAFT,
        ]);

        ProductOffer::create([
            'product_id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'external_id' => $externalId,
            'cost_price' => 80,
            'stock' => 1,
        ]);
    }

    /** @return array<int, string> */
    protected function scan(): array
    {
        $driver = new EliteDriver($this->supplier->fresh());

        $method = new \ReflectionMethod($driver, 'idsToScan');
        $method->setAccessible(true);

        return iterator_to_array($method->invoke($driver, 35000), false);
    }
}
