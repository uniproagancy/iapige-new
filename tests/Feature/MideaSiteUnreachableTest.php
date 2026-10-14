<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Midea\MideaDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One slow page on their side emptied our catalogue.
 *
 * midea.ge's listing pages began timing out at ten seconds each. The exception
 * travelled up out of the crawl, through fetch(), and the job caught it — so
 * the product was skipped entirely. Not imported without a photograph:
 * not imported at all, though the price list had its price and its stock the
 * whole time.
 *
 * And it was paid for again on every product, because an index that could not
 * be built was never remembered as such: each of two hundred products spent
 * half a minute learning what the one before it had just learned.
 *
 * The website is an enrichment. It decides nothing about whether a product
 * exists or what it costs.
 */
class MideaSiteUnreachableTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /** How many times the listing crawl was attempted. */
    protected int $crawlAttempts = 0;

    /* ------------------------------------------------------------------ the product survives */

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

    /* ------------------------------------------------------------------ the cost is paid once */

    /**
     * A source that could not be reached is not asked again immediately.
     *
     * Not cached as the truth — an empty crawl would otherwise be believed for
     * six hours — but remembered as a failure, briefly.
     */
    public function test_a_failed_crawl_is_not_repeated_for_every_product(): void
    {
        $this->fakeTimeout();

        $this->payload();

        $paid = $this->crawlAttempts;

        $this->assertGreaterThan(0, $paid, 'the first product pays for the crawl');

        $this->payload();

        $this->assertSame($paid, $this->crawlAttempts, 'the second product must not pay for it again');
    }

    public function test_the_failure_is_remembered_under_its_own_key(): void
    {
        $this->fakeTimeout();

        $this->payload();

        $this->assertTrue((bool) Cache::get('import:midea:site-index:failed'));
        $this->assertNull(Cache::get('import:midea:site-index'));
    }

    /** The next run asks again, because the site may have come back. */
    public function test_the_next_run_tries_again(): void
    {
        $this->fakeTimeout();

        $this->payload();

        $paid = $this->crawlAttempts;

        Cache::forget('import:midea:site-index:failed');

        $this->payload();

        $this->assertGreaterThan($paid, $this->crawlAttempts);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

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
                'max_pages' => 1,
                'listings' => ['/ka/products/kondicioneri/split-sistema/all/1/1/'],
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

    /** The search answers with nothing; everything else times out. */
    protected function fakeTimeout(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/search')) {
                return Http::response(['status' => 1, 'products' => []], 200);
            }

            // counted here rather than through Http::recorded(), which does
            // not record a request that never got a response
            $this->crawlAttempts++;

            throw new ConnectionException('cURL error 28: Connection timed out after 10001 milliseconds');
        });
    }
}
