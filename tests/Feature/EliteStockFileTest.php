<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Elite\BlockedByElite;
use App\Services\Import\Drivers\Elite\EliteClient;
use App\Services\Import\Drivers\Elite\EliteDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The spreadsheet decides what Elite sells us.
 *
 * The driver has always said so in its own docblock — "a product is imported
 * only when the barcode the API returns is present in the latest spreadsheet"
 * — and never checked. Only an empty barcode was refused, so every product the
 * id scan happened to find went into the catalogue whether Elite stocked it or
 * not: rows at stock zero for products nobody had sent us a price for.
 */
class EliteStockFileTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'code' => 'elite', 'name' => 'Elite', 'driver' => EliteDriver::class,
            'is_active' => true, 'priority' => 1, 'markup' => [['percent' => 15]],
            'config' => ['locale' => 'ka'],
        ]);
    }

    public function test_a_barcode_in_the_spreadsheet_is_imported(): void
    {
        $this->stockFile(['IN-STOCK' => 7]);

        $payload = $this->payloadFor('IN-STOCK');

        $this->assertNotNull($payload);
        $this->assertSame('elite-IN-STOCK', $payload->sku);
        $this->assertSame(7, $payload->stock);
    }

    /**
     * Listed at zero is a product Elite sells and has run out of, which is not
     * the same thing as a product that is not theirs — it stays, unavailable.
     */
    public function test_a_barcode_listed_at_zero_is_still_imported(): void
    {
        $this->stockFile(['SOLD-OUT' => 0]);

        $payload = $this->payloadFor('SOLD-OUT');

        $this->assertNotNull($payload);
        $this->assertSame(0, $payload->stock);
    }

    public function test_a_barcode_missing_from_the_spreadsheet_is_refused(): void
    {
        $this->stockFile(['SOMETHING-ELSE' => 5]);

        $this->assertNull($this->payloadFor('NOT-IN-THE-FILE'));
    }

    /** With no spreadsheet loaded there is nothing Elite has offered us. */
    public function test_nothing_is_imported_without_a_spreadsheet(): void
    {
        $this->assertSame(0, SupplierStock::where('supplier_id', $this->supplier->id)->count());

        $this->assertNull($this->payloadFor('ANY-BARCODE'));
    }

    public function test_a_product_with_no_barcode_is_refused(): void
    {
        $this->stockFile(['IN-STOCK' => 7]);

        $this->assertNull($this->payloadFor(''));
    }

    /**
     * Elite's price pair is its own.
     *
     * Zoommer's API is identical in shape and reads the lower figure as the
     * cost; Elite prices a different thing, so its mapping is deliberately
     * left alone and must not be quietly aligned with Zoommer's.
     */
    public function test_the_price_pair_is_read_as_elite_sends_it(): void
    {
        $this->stockFile(['IN-STOCK' => 1]);

        $payload = $this->payloadFor('IN-STOCK', ['price' => 1399, 'previousPrice' => 1599]);

        $this->assertSame(1599.0, $payload->costPrice);
        $this->assertSame(1399.0, $payload->oldCostPrice);
    }

    /**
     * A refusal from the proxy is not a missing product.
     *
     * Both used to return null, so an expired worker token turned a run of
     * thirty-five thousand ids into thirty-five thousand silent nothings with
     * nothing anywhere to say the token needed replacing.
     *
     * One case per test, because Http::fake keeps the stub it matched first and
     * a loop would measure the same response four times.
     */
    #[DataProvider('refusals')]
    public function test_a_refusal_from_the_proxy_is_loud(int $status, string $expected): void
    {
        Http::fake(fn () => Http::response('denied', $status));

        try {
            (new EliteClient)->product('500');
            $this->fail("HTTP {$status} should have been refused");
        } catch (BlockedByElite $e) {
            $this->assertStringContainsString("HTTP {$status}", $e->getMessage());
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    public static function refusals(): array
    {
        return [
            'bad token' => [401, 'ELITE_TOKEN'],
            'forbidden' => [403, 'ELITE_TOKEN'],
            'throttled' => [429, 'IMPORT_RATE_PER_MINUTE'],
            'proxy down' => [503, 'unavailable'],
        ];
    }

    /** The proxy's own complaint about our query is ours to fix, so it is raised. */
    public function test_a_proxy_error_payload_is_raised(): void
    {
        Http::fake(fn () => Http::response(['error' => 'type must be: product or image'], 400));

        $this->expectException(BlockedByElite::class);
        $this->expectExceptionMessage('type must be: product or image');

        (new EliteClient)->product('500');
    }

    /** A product that is not there answers 200 with a null product, and that is normal. */
    public function test_a_missing_product_stays_quiet(): void
    {
        Http::fake(fn () => Http::response([
            'product' => null, 'availabilityInStores' => null,
            'httpStatusCode' => 400, 'userMessage' => 'პროდუქტი ვერ მოიძებნა',
        ]));

        $this->assertNull((new EliteClient)->product('999999'));
    }

    public function test_a_real_product_comes_back(): void
    {
        Http::fake(fn () => Http::response([
            'product' => ['id' => 500, 'barCode' => 'I33436', 'name' => 'BOSCH MFW66020'],
            'availabilityInStores' => [],
        ]));

        $data = (new EliteClient)->product('500');

        $this->assertSame('I33436', $data['product']['barCode']);
    }

    /* ------------------------------------------------------------------ helpers */

    /** @param  array<string, int>  $barcodes */
    protected function stockFile(array $barcodes): void
    {
        foreach ($barcodes as $barcode => $quantity) {
            SupplierStock::create([
                'supplier_id' => $this->supplier->id,
                'external_id' => $barcode,
                'quantity' => $quantity,
                'cost_price' => 100,
                'data' => [],
                'synced_at' => now(),
            ]);
        }
    }

    protected function payloadFor(string $barcode, array $prices = ['price' => 500])
    {
        $driver = new EliteDriver($this->supplier);

        $method = new ReflectionMethod($driver, 'toPayload');
        $method->setAccessible(true);

        return $method->invoke($driver, '1', ['product' => $prices + [
            'barCode' => $barcode,
            'name' => 'ელიტის პროდუქტი',
            'categoryName' => 'ტელევიზორები',
            'brandName' => 'Xiaomi',
            'images' => [],
            'specificationGroup' => [],
        ]]);
    }
}
