<?php

declare(strict_types=1);

namespace AltaTests;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaB2bClient;
use App\Services\Import\Drivers\Alta\AltaClient;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;

final class AltaStockRefreshTest extends TestCase
{
    protected function supplier(): Supplier
    {
        $supplier = Supplier::create(['code' => 'alta', 'name' => 'Alta fixture', 'driver' => AltaDriver::class,
            'is_active' => true, 'priority' => 20, 'config' => ['locale' => 'ka'], 'markup' => []]);
        DB::table('supplier_stocks')->insert(['supplier_id' => $supplier->id, 'external_id' => '001234',
            'quantity' => 4, 'cost_price' => 599, 'data' => '{"model":"Saved model"}', 'synced_at' => '2026-09-30 09:00:00']);

        return $supplier;
    }

    public function test_empty_soap_response_never_zeroes_or_replaces_saved_stock(): void
    {
        $supplier = $this->supplier();
        $before = $this->rows();
        $b2b = Mockery::mock(AltaB2bClient::class);
        $b2b->shouldReceive('all')->andReturn([]);
        $site = Mockery::mock(AltaClient::class);
        $site->shouldNotReceive('product');
        try {
            (new AltaDriver($supplier, $b2b, $site))->refreshStock();
            self::fail('An empty feed cannot be treated as a successful authoritative stock snapshot.');
        } catch (AltaUnavailable) {
            self::assertSame($before, $this->rows());
        }
        Http::assertNothingSent();
    }

    public function test_soap_exception_preserves_saved_snapshot(): void
    {
        $supplier = $this->supplier();
        $before = $this->rows();
        $b2b = Mockery::mock(AltaB2bClient::class);
        $b2b->shouldReceive('all')->andThrow(new AltaUnavailable('Synthetic SOAP unavailable'));
        $site = Mockery::mock(AltaClient::class);
        $site->shouldNotReceive('product');
        try {
            (new AltaDriver($supplier, $b2b, $site))->refreshStock();
            self::fail('A failed feed cannot replace the previous snapshot.');
        } catch (AltaUnavailable) {
            self::assertSame($before, $this->rows());
        }
    }

    public function test_valid_soap_stock_refresh_does_not_fetch_or_invent_retail_prices(): void
    {
        $supplier = $this->supplier();
        $b2b = Mockery::mock(AltaB2bClient::class);
        $b2b->shouldReceive('all')->andReturn([['item' => '001234', 'quantity' => 2, 'price' => 599,
            'model' => 'Updated SOAP model', 'name' => 'Synthetic SOAP item', 'weight' => 1200]]);
        $site = Mockery::mock(AltaClient::class);
        $site->shouldNotReceive('product');

        self::assertSame(1, (new AltaDriver($supplier, $b2b, $site))->refreshStock());
        self::assertSame(2, (int) DB::table('supplier_stocks')->where('external_id', '001234')->value('quantity'));
        self::assertSame(0, DB::table('products')->count());
        self::assertSame(0, DB::table('product_offers')->count());
        Http::assertNothingSent();
    }

    protected function rows(): array
    {
        return DB::table('supplier_stocks')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
}
