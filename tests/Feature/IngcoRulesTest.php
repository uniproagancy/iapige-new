<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Ingco\IngcoDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The two commercial rules on Ingco's price list.
 *
 * A tool under 120 lari is not worth listing — it costs more to handle and
 * photograph than it returns — and a line with a handful left is the one that
 * turns into a cancelled order. Both figures are the supplier's own config, and
 * both are compared against the number the supplier actually charges: column B,
 * the promotional price, not the list price printed beside it.
 */
class IngcoRulesTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'code' => 'ingco', 'name' => 'Ingco', 'driver' => IngcoDriver::class,
            'is_active' => true, 'priority' => 1, 'markup' => [['percent' => 20]],
            'config' => [
                'locale' => 'ka', 'brand' => 'INGCO',
                'columns' => ['key' => 'A', 'sale' => 'B', 'cost' => 'C', 'stock' => 'D'],
                'min_price' => 120, 'min_stock' => 5,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ price */

    /**
     * The floor is measured against what we pay, not the figure beside it.
     *
     * It used to read the list price, so a tool charged at 105 cleared a floor
     * of 120 on the strength of the 126 printed next to it.
     */
    public function test_the_floor_reads_the_promotional_price(): void
    {
        $row = $this->row(sale: 105, list: 126);

        $this->assertSame([105.0, 126.0], $this->prices($row));
    }

    #[DataProvider('priceCases')]
    public function test_the_price_pair_is_read_correctly(float $sale, float $list, float $expected, ?float $old): void
    {
        [$price, $was] = $this->prices($this->row(sale: $sale, list: $list));

        $this->assertSame($expected, $price);
        $this->assertSame($old, $was);
    }

    public static function priceCases(): array
    {
        return [
            'a promotion is what we pay' => [105.0, 126.0, 105.0, 126.0],
            'no promotion, so the list price stands' => [0.0, 300.0, 300.0, null],
            // a promotion above the list price is not a promotion
            'promotion not below the list' => [400.0, 300.0, 300.0, null],
            'equal figures are no comparison' => [300.0, 300.0, 300.0, null],
        ];
    }

    /* ------------------------------------------------------------------ stock */

    #[DataProvider('stockCases')]
    public function test_a_thin_line_reads_as_unavailable(int $quantity, int $expected): void
    {
        $driver = new IngcoDriver($this->supplier);
        $method = new ReflectionMethod($driver, 'sellableStock');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($driver, $quantity));
    }

    public static function stockCases(): array
    {
        return [
            'none' => [0, 0],
            'one left' => [1, 0],
            'four left' => [4, 0],
            'exactly the floor' => [5, 5],
            'plenty' => [168, 168],
        ];
    }

    /**
     * Below the floor the product stays and reads as unavailable.
     *
     * Dropping it instead would take it off the shop and lose its category,
     * pictures and specifications; this way it returns on its own when the
     * supplier restocks.
     */
    public function test_a_thin_line_is_not_deleted_only_marked(): void
    {
        $row = $this->row(sale: 300, list: 360, quantity: 2);
        $row->save();

        $driver = new IngcoDriver($this->supplier);
        $method = new ReflectionMethod($driver, 'sellableStock');
        $method->setAccessible(true);

        $this->assertSame(0, $method->invoke($driver, (int) $row->quantity));

        // the rule changes what we offer, never what the supplier told us
        $this->assertSame(2, (int) $row->fresh()->quantity);
        $this->assertDatabaseCount('supplier_stocks', 1);
    }

    /** With no floor configured every quantity stands. */
    public function test_the_stock_rule_is_off_without_a_floor(): void
    {
        $this->supplier->update(['config' => ['min_stock' => 0] + $this->supplier->config]);

        $driver = new IngcoDriver($this->supplier);
        $method = new ReflectionMethod($driver, 'sellableStock');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke($driver, 1));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function row(float $sale, float $list, int $quantity = 10): SupplierStock
    {
        return SupplierStock::make([
            'supplier_id' => $this->supplier->id,
            'external_id' => '1001201',
            'quantity' => $quantity,
            'cost_price' => $list,
            'data' => $sale > 0 ? ['sale' => $sale] : [],
            'synced_at' => now(),
        ]);
    }

    /** @return array{0: float, 1: ?float} */
    protected function prices(SupplierStock $row): array
    {
        $driver = new IngcoDriver($this->supplier);
        $method = new ReflectionMethod($driver, 'prices');
        $method->setAccessible(true);

        return $method->invoke($driver, $row);
    }
}
