<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Midea\MideaDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One slow page on their side emptied our catalogue.
 *
 * midea.ge began timing out at ten seconds a request. The exception travelled
 * up out of the client, through fetch(), and the job caught it — so the
 * product was skipped entirely. Not imported without a photograph: not
 * imported at all, though the price list had its price and its stock the whole
 * time.
 *
 * The website is an enrichment. It decides nothing about whether a product
 * exists or what it costs.
 */
class MideaSiteUnreachableTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    public function test_a_product_is_imported_when_the_site_times_out(): void
    {
        $this->fakeTimeout();

        $payload = $this->payload();

        $this->assertNotNull($payload);
        $this->assertSame(1299.0, $payload->costPrice);
        $this->assertSame(4, $payload->stock);
    }

    /** Without a photograph, which is the only thing the site was for. */
    public function test_it_arrives_without_pictures(): void
    {
        $this->fakeTimeout();

        $this->assertSame([], $this->payload()->images);
    }

    /** And named from the spreadsheet, since the site could not say better. */
    public function test_it_is_named_from_the_price_list(): void
    {
        $this->fakeTimeout();

        $this->assertSame('წვრილი ტექნიკა FS40-21MR', $this->payload()->translations['ka']['name']);
    }

    /** A refusal is not a crash either: the product still arrives. */
    public function test_a_refused_request_leaves_the_product_alone(): void
    {
        Http::fake(['*' => Http::response('denied', 403)]);

        $payload = $this->payload();

        $this->assertNotNull($payload);
        $this->assertSame([], $payload->images);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'code' => 'midea',
            'name' => 'Midea',
            'driver' => MideaDriver::class,
            'is_active' => true,
            'priority' => 40,
            'markup' => [['percent' => 20]],
            'config' => [
                'locale' => 'ka',
                'brand' => 'Midea',
                'read_site' => true,
                'price_from' => 'retail',
                'columns' => ['key' => 'C', 'category' => 'A', 'model' => 'B', 'retail' => 'E', 'stock' => 'H'],
            ],
        ]);

        SupplierStock::create([
            'supplier_id' => $this->supplier->id,
            'external_id' => '2000000020419',
            'quantity' => 4,
            'cost_price' => 1000,
            'data' => [
                'model' => 'FS40-21MR',
                'category' => 'წვრილი ტექნიკა',
                'retail' => '1299',
            ],
            'synced_at' => now(),
        ]);
    }

    protected function payload()
    {
        return (new MideaDriver($this->supplier->fresh()))->fetch('2000000020419');
    }

    protected function fakeTimeout(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out after 10001 milliseconds');
        });
    }
}
