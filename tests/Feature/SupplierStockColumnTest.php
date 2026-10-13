<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\PriceListLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INGCO reported one of everything, for months, in silence.
 *
 * Its price list carries the quantity in column D and the seeder has said so
 * for a long time — but a seeder only runs on a fresh database, and the live
 * row predates that line. So the map it actually held named the model and the
 * two prices and nothing else.
 *
 * A missing stock column means "this supplier does not report stock", and the
 * loader counts such a row as one available, which is right for the suppliers
 * that genuinely do not. The prices came from the columns that were mapped and
 * were exactly right, so a tool with fifteen in the file sat in the admin
 * showing one, at the correct price, looking perfectly healthy.
 *
 * The reading is kept, because it is the right one. What changes is that the
 * loader now says it is doing it.
 */
class SupplierStockColumnTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ the reading */

    public function test_a_mapped_stock_column_is_read(): void
    {
        $supplier = $this->supplier(['key' => 'A', 'sale' => 'B', 'cost' => 'C', 'stock' => 'D']);

        $result = $this->load($supplier);

        $this->assertFalse($result['no_stock_column']);
        $this->assertSame(15, SupplierStock::where('external_id', '1007081')->value('quantity'));
    }

    /** Unmapped, every row is one available — which is the silent part. */
    public function test_an_unmapped_stock_column_counts_every_row_as_one(): void
    {
        $supplier = $this->supplier(['key' => 'A', 'sale' => 'B', 'cost' => 'C']);

        $this->load($supplier);

        $this->assertSame(1, SupplierStock::where('external_id', '1007081')->value('quantity'));
    }

    /**
     * And says so.
     *
     * This is the whole fix: the behaviour is unchanged and no longer silent.
     */
    public function test_an_unmapped_stock_column_is_reported(): void
    {
        $supplier = $this->supplier(['key' => 'A', 'sale' => 'B', 'cost' => 'C']);

        $this->assertTrue($this->load($supplier)['no_stock_column']);
    }

    public function test_it_is_reported_even_when_the_file_was_empty(): void
    {
        $supplier = $this->supplier(['key' => 'A']);

        $path = $this->file("\n");

        $result = (new PriceListLoader)->load($supplier, $path);

        unlink($path);

        $this->assertSame(0, $result['read']);
        $this->assertTrue($result['no_stock_column']);
    }

    /** The prices are right either way, which is why nothing looked wrong. */
    public function test_the_prices_are_read_whatever_the_stock_column_does(): void
    {
        $supplier = $this->supplier(['key' => 'A', 'sale' => 'B', 'cost' => 'C']);

        $this->load($supplier);

        $row = SupplierStock::where('external_id', '1007081')->first();

        $this->assertSame('450.00', $row->cost_price);
        $this->assertSame('375', $row->data['sale']);
    }

    /* ------------------------------------------------------------------ the repair */

    /**
     * The migration puts the column back on a row that lost it.
     *
     * Merged, not written over: a letter corrected by hand on the live row has
     * to survive, because that correction is the reason somebody went in.
     */
    public function test_the_repair_adds_the_missing_column(): void
    {
        $supplier = $this->supplier(['key' => 'A', 'sale' => 'B', 'cost' => 'C'], code: 'ingco');

        $this->repair();

        $config = $supplier->fresh()->config;

        $this->assertSame('D', $config['columns']['stock']);
        $this->assertSame(5, $config['min_stock']);
        $this->assertSame(120, $config['min_price']);
    }

    public function test_the_repair_keeps_a_letter_set_by_hand(): void
    {
        $supplier = $this->supplier(
            ['key' => 'A', 'sale' => 'B', 'cost' => 'C', 'stock' => 'F'],
            code: 'ingco',
        );

        $this->repair();

        $this->assertSame('F', $supplier->fresh()->config['columns']['stock']);
    }

    public function test_the_repair_keeps_everything_else_on_the_row(): void
    {
        $supplier = $this->supplier(['key' => 'A'], code: 'ingco');
        $supplier->update(['config' => $supplier->config + ['timeout' => 90, 'locale' => 'ka']]);

        $this->repair();

        $config = $supplier->fresh()->config;

        $this->assertSame(90, $config['timeout']);
        $this->assertSame('ka', $config['locale']);
    }

    public function test_the_repair_leaves_other_suppliers_alone(): void
    {
        $other = $this->supplier(['key' => 'A'], code: 'alta');

        $this->repair();

        $this->assertArrayNotHasKey('stock', $other->fresh()->config['columns']);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function supplier(array $columns, string $code = 'ingco'): Supplier
    {
        return Supplier::create([
            'code' => $code,
            'name' => strtoupper($code),
            'driver' => 'X',
            'is_active' => true,
            'priority' => 60,
            'markup' => [['up_to' => 200, 'percent' => 25], ['percent' => 20]],
            'config' => ['columns' => $columns],
        ]);
    }

    /** @return array{read:int, zeroed:int, missing_url:int, no_stock_column:bool} */
    protected function load(Supplier $supplier): array
    {
        // the row from the supplier's own file: model, promo, list, quantity
        $path = $this->file("model,sale,list,qty\n1007081,375,450,15\n1837744,215,258,12\n");

        $result = (new PriceListLoader)->load($supplier, $path);

        unlink($path);

        return $result;
    }

    protected function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'prices').'.csv';

        file_put_contents($path, $contents);

        return $path;
    }

    protected function repair(): void
    {
        $migration = require database_path('migrations/2026_10_13_140000_repair_ingco_stock_column.php');

        $migration->up();
    }
}
