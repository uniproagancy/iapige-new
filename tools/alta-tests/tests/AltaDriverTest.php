<?php

declare(strict_types=1);

namespace AltaTests;

use App\Jobs\ImportProductJob;
use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaB2bClient;
use App\Services\Import\Drivers\Alta\AltaClient;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use App\Services\Import\ImportManager;
use App\Services\Import\ProductImporter;
use App\Services\Import\SupplierDriver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;

final class AltaDriverTest extends TestCase
{
    protected function supplier(): Supplier
    {
        return Supplier::create(['code' => 'alta', 'name' => 'Alta fixture', 'is_active' => true,
            'driver' => AltaDriver::class, 'config' => ['locale' => 'ka'], 'markup' => [], 'priority' => 20]);
    }

    protected function driver(?array $details, array $row = []): AltaDriver
    {
        $row = array_replace(['item' => '001234', 'quantity' => 3, 'price' => 599.0,
            'model' => 'Synthetic Model', 'name' => 'SOAP name', 'brand' => 'SOAP brand',
            'category' => 'SOAP category', 'weight' => 1200], $row);
        $b2b = Mockery::mock(AltaB2bClient::class);
        $b2b->shouldReceive('one')->with('001234')->andReturn($row);
        $b2b->shouldReceive('all')->andReturn([$row]);
        $site = Mockery::mock(AltaClient::class);
        $site->shouldReceive('product')->with('001234')->andReturn($details);

        return new AltaDriver($this->supplier(), $b2b, $site);
    }

    public function test_website_current_price_media_and_description_win_while_soap_quantity_is_authoritative(): void
    {
        $payload = $this->driver($this->details(), ['price' => 599.0, 'quantity' => 3])->fetch('001234');
        self::assertNotNull($payload);
        self::assertSame('001234', $payload->externalId);
        self::assertSame('alta-001234', $payload->sku);
        self::assertSame(799.0, $payload->costPrice, 'Dealer price must never become the requested retail price.');
        self::assertSame(999.0, $payload->oldCostPrice);
        self::assertSame(3, $payload->stock, 'Branch count and website storageQuantity are not B2B quantity.');
        self::assertSame('Synthetic Alta Laptop', $payload->translations['ka']['name']);
        self::assertSame('<p>Synthetic exact-product description.</p>', $payload->translations['ka']['description']);
        self::assertSame(['https://images.invalid/primary.webp', 'https://images.invalid/side.webp'], $payload->images);
        self::assertSame('16 GB', $payload->specs[0]['value']);
    }

    public function test_zero_soap_stock_is_not_replaced_by_website_branch_availability(): void
    {
        $payload = $this->driver($this->details(['storageQuantity' => 70], [['inStock' => true]]), ['quantity' => 0])->fetch('001234');
        self::assertNotNull($payload);
        self::assertSame(0, $payload->stock);
        self::assertSame(799.0, $payload->costPrice);
    }

    public function test_missing_positive_retail_price_does_not_fall_back_to_dealer_or_previous_price(): void
    {
        foreach ([0, -1, null] as $price) {
            $payload = $this->driver($this->details(['price' => $price, 'previousPrice' => 999]), ['price' => 599])->fetch('001234');
            self::assertNull($payload);
        }
    }

    public function test_an_absent_website_match_is_not_imported_from_the_soap_row_alone(): void
    {
        self::assertNull($this->driver(null)->fetch('001234'));
    }

    public function test_fractional_retail_price_is_rejected_instead_of_silently_rounded_by_generic_markup(): void
    {
        $this->expectException(AltaUnavailable::class);
        $this->driver($this->details(['price' => 799.50]))->fetch('001234');
    }

    public function test_driver_rejects_a_mismatched_website_barcode_even_if_client_contract_is_broken(): void
    {
        self::assertNull($this->driver($this->details(['barCode' => '009999']))->fetch('001234'));
    }

    public function test_unavailable_retail_fetch_never_calls_importer_or_changes_an_existing_product_offer(): void
    {
        $supplier = $this->supplier();
        $before = $this->existing($supplier);
        $b2b = Mockery::mock(AltaB2bClient::class);
        $b2b->shouldReceive('one')->with('001234')->andReturn(['item' => '001234', 'quantity' => 0, 'price' => 599]);
        $site = Mockery::mock(AltaClient::class);
        $site->shouldReceive('product')->with('001234')->andThrow(new AltaUnavailable('Synthetic unavailable storefront'));
        $driver = new AltaDriver($supplier, $b2b, $site);
        $importer = Mockery::mock(ProductImporter::class);
        $importer->shouldNotReceive('import');
        $manager = $this->manager($importer, $driver);
        try {
            (new ImportProductJob($supplier->id, '001234'))->handle($manager, $importer);
        } catch (AltaUnavailable) {
            // Both durable retries and current log-and-return preserve saved products.
        }
        self::assertSame($before, $this->savedState());
        Http::assertNothingSent();
    }

    public function test_failed_retail_preflight_happens_before_stale_empty_rows_can_zero_existing_offers(): void
    {
        $supplier = $this->supplier();
        $before = $this->existing($supplier);
        DB::table('supplier_stocks')->insert(['supplier_id' => $supplier->id, 'external_id' => '001234',
            'quantity' => 0, 'cost_price' => 599, 'data' => '{}', 'synced_at' => '2026-09-30 09:00:00']);
        $b2b = Mockery::mock(AltaB2bClient::class);
        $b2b->shouldReceive('all')->andReturn([['item' => '001234', 'quantity' => 3, 'price' => 599]]);
        $site = Mockery::mock(AltaClient::class);
        $site->shouldReceive('product')->with('001234')->andThrow(new AltaUnavailable('Synthetic HTTP 403'));
        $importer = Mockery::mock(ProductImporter::class);
        $importer->shouldNotReceive('import');
        $importer->shouldNotReceive('refreshFromOffers');
        $driver = new AltaDriver($supplier, $b2b, $site);
        try {
            $this->manager($importer, $driver)->run($supplier);
            self::fail('Unavailable storefront must fail the batch before queuing any product.');
        } catch (AltaUnavailable) {
            self::assertSame($before, $this->savedState());
            self::assertNull($supplier->fresh()->last_run_at);
        }
    }

    protected function existing(Supplier $supplier): array
    {
        DB::table('products')->insert(['id' => 16, 'sku' => 'alta-001234', 'price' => 799, 'old_price' => 999,
            'cost_price' => 799, 'price_lock' => false, 'stock' => 4, 'status' => 'active']);
        DB::table('product_offers')->insert(['product_id' => 16, 'supplier_id' => $supplier->id,
            'external_id' => '001234', 'cost_price' => 799, 'old_cost_price' => 999, 'stock' => 4]);

        return $this->savedState();
    }

    protected function savedState(): array
    {
        return ['products' => DB::table('products')->get()->map(fn ($r) => (array) $r)->all(),
            'offers' => DB::table('product_offers')->get()->map(fn ($r) => (array) $r)->all()];
    }

    protected function manager(ProductImporter $importer, SupplierDriver $driver): ImportManager
    {
        return new class($importer, $driver) extends ImportManager {
            public function __construct(ProductImporter $importer, private SupplierDriver $fixtureDriver)
            {
                parent::__construct($importer);
            }

            public function driver(Supplier $supplier): SupplierDriver
            {
                return $this->fixtureDriver;
            }
        };
    }
}
