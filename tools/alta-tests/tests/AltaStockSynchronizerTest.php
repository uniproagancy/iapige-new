<?php

declare(strict_types=1);

namespace AltaTests;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaB2bClient;
use App\Services\Import\Drivers\Alta\AltaClient;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaStockSynchronizer;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use Illuminate\Support\Facades\DB;
use Mockery;

final class AltaStockSynchronizerTest extends TestCase
{
    protected function fixture(): Supplier
    {
        $supplier = Supplier::create(['code' => 'alta', 'name' => 'Alta fixture', 'driver' => AltaDriver::class,
            'is_active' => true, 'priority' => 20, 'config' => [], 'markup' => []]);
        $other = Supplier::create(['code' => 'other', 'name' => 'Other fixture', 'driver' => AltaDriver::class,
            'is_active' => true, 'priority' => 30, 'config' => [], 'markup' => []]);
        $inactive = Supplier::create(['code' => 'inactive', 'name' => 'Inactive fixture', 'driver' => AltaDriver::class,
            'is_active' => false, 'priority' => 40, 'config' => [], 'markup' => []]);
        foreach ([1 => '001234', 2 => 'zero', 3 => 'missing'] as $id => $code) {
            DB::table('products')->insert(['id' => $id, 'sku' => 'alta-'.$code, 'price' => 799 + $id,
                'old_price' => 999 + $id, 'cost_price' => 799 + $id, 'stock' => 4, 'status' => 'active']);
            DB::table('product_offers')->insert(['product_id' => $id, 'supplier_id' => $supplier->id,
                'external_id' => $code, 'cost_price' => 799 + $id, 'old_cost_price' => 999 + $id, 'stock' => 4]);
            DB::table('supplier_stocks')->insert(['supplier_id' => $supplier->id, 'external_id' => $code,
                'quantity' => 4, 'cost_price' => 599, 'data' => '{}']);
        }
        foreach ([[$other, 2], [$inactive, 50]] as [$source, $quantity]) {
            DB::table('product_offers')->insert(['product_id' => 1, 'supplier_id' => $source->id,
                'external_id' => 'other-001234', 'cost_price' => 500, 'stock' => $quantity]);
        }

        return $supplier;
    }

    public function test_valid_snapshot_updates_zero_and_missing_items_without_touching_any_price(): void
    {
        $supplier = $this->fixture();
        $prices = $this->prices();
        $b2b = Mockery::mock(AltaB2bClient::class);
        $b2b->shouldReceive('all')->once()->andReturn([
            ['item' => '001234', 'quantity' => 3, 'price' => 400],
            ['item' => 'zero', 'quantity' => 0, 'price' => 300],
        ]);
        $site = Mockery::mock(AltaClient::class);
        $site->shouldNotReceive('product');

        $result = (new AltaStockSynchronizer)->sync($supplier, new AltaDriver($supplier, $b2b, $site));
        self::assertSame(['rows' => 2, 'offers_changed' => 3, 'products_changed' => 3], $result);
        self::assertSame([3, 0, 0], DB::table('product_offers')->where('supplier_id', $supplier->id)->orderBy('id')->pluck('stock')->all());
        self::assertSame([5, 0, 0], DB::table('products')->orderBy('id')->pluck('stock')->all(), 'Only active supplier quantities contribute.');
        self::assertSame([3, 0, 0], DB::table('supplier_stocks')->where('supplier_id', $supplier->id)->orderBy('id')->pluck('quantity')->all());
        self::assertSame($prices, $this->prices(), 'Stock synchronization must not replace website retail or old prices with SOAP prices.');
    }

    public function test_empty_or_malformed_stock_snapshots_preserve_every_saved_row(): void
    {
        $supplier = $this->fixture();
        $before = $this->state();
        foreach ([[], [['item' => '001234', 'price' => 1]], [['item' => '001234', 'quantity' => -1]]] as $rows) {
            $b2b = Mockery::mock(AltaB2bClient::class);
            $b2b->shouldReceive('all')->andReturn($rows);
            $site = Mockery::mock(AltaClient::class);
            $site->shouldNotReceive('product');
            try {
                (new AltaStockSynchronizer)->sync($supplier, new AltaDriver($supplier, $b2b, $site));
                self::fail('Incomplete snapshots must be rejected before saved rows change.');
            } catch (AltaUnavailable) {
                self::assertSame($before, $this->state());
            }
        }
    }

    protected function prices(): array
    {
        return [DB::table('products')->orderBy('id')->get(['id', 'price', 'old_price', 'cost_price'])->map(fn ($r) => (array) $r)->all(),
            DB::table('product_offers')->orderBy('id')->get(['id', 'cost_price', 'old_cost_price'])->map(fn ($r) => (array) $r)->all()];
    }

    protected function state(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), ['products', 'product_offers', 'supplier_stocks']);
    }
}
