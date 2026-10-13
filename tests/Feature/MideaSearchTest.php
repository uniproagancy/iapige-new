<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Midea\MideaDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Midea products arrived with no photograph and a made-up name.
 *
 * The long way round was: crawl seven category listings page by page, index
 * every card by model code, fall back to the sitemap when the index missed,
 * and fetch the product page when the card had no picture. Hundreds of
 * requests and a six hour cache — and still only the models those seven
 * listings happened to cover. Everything else arrived with no photograph at
 * all and a name assembled out of the spreadsheet's category and model, which
 * is why the admin was full of "წვრილი ტექნიკა FS40-21MR".
 *
 * The shop's own header search answers with the whole product in one request:
 * the title, both prices, the main photograph and the gallery, the model
 * number, the stock flag and the weight. So that is what is asked first.
 */
class MideaSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ the search */

    public function test_the_search_answers_with_the_whole_product(): void
    {
        $this->fakeSearch();

        $payload = $this->payload();

        $this->assertSame('ვენტილატორი FS40-21MR', $payload->translations['ka']['name']);
        $this->assertStringContainsString('სიმძლავრე', $payload->translations['ka']['description']);
        $this->assertSame(25, $payload->weight);
    }

    /** The main photograph and the gallery, both. */
    public function test_the_gallery_comes_with_it(): void
    {
        $this->fakeSearch();

        $this->assertSame([
            'https://www.midea.ge/uploads/products/main.jpg',
            'https://www.midea.ge/uploads/products/gallery-1.jpg',
            'https://www.midea.ge/uploads/products/gallery-2.jpg',
        ], $this->payload()->images);
    }

    /** One request, where the old way took hundreds. */
    public function test_it_asks_once(): void
    {
        $this->fakeSearch();

        $this->payload();

        Http::assertSentCount(1);
    }

    /* ------------------------------------------------------------------ the wrong product */

    /**
     * A search is a search.
     *
     * Ask it for a model and it answers with everything whose text mentions
     * it, so the answer is only taken when its own model_number matches. A
     * product wearing another product's photographs is worse than one with
     * none.
     */
    public function test_a_result_for_a_different_model_is_refused(): void
    {
        $this->fakeSearch(modelNumber: 'SOMETHING-ELSE');

        $payload = $this->payload();

        // the spreadsheet's own category and model, which is the fallback
        $this->assertSame('წვრილი ტექნიკა FS40-21MR', $payload->translations['ka']['name']);
        $this->assertSame([], $payload->images);
    }

    /** The site writes the brand in front of some model numbers and not others. */
    public function test_a_model_number_carrying_the_brand_still_matches(): void
    {
        $this->fakeSearch(modelNumber: 'Midea FS40-21MR');

        $this->assertSame('ვენტილატორი FS40-21MR', $this->payload()->translations['ka']['name']);
    }

    public function test_a_search_that_finds_nothing_leaves_the_product_alone(): void
    {
        Http::fake(['*' => Http::response(['status' => 1, 'products' => []], 200)]);

        $this->assertSame([], $this->payload()->images);
    }

    /** status 0 means it did not understand the request, not that there is nothing. */
    public function test_a_refused_request_is_not_an_empty_result(): void
    {
        Http::fake(['*' => Http::response(['status' => 0, 'products' => null], 200)]);

        $this->assertSame([], $this->payload()->images);
    }

    /* ------------------------------------------------------------------ the link */

    /** The site writes its own links with a doubled slash after the locale. */
    public function test_a_doubled_slash_in_the_link_is_straightened(): void
    {
        $this->fakeSearch();

        $card = (new \ReflectionMethod(MideaDriver::class, 'siteCard'));
        $card->setAccessible(true);

        $this->assertSame(
            'https://www.midea.ge/ka/product/ventilatori-fs40-21mr/431',
            $card->invoke($this->driver(), 'FS40-21MR')['url'],
        );
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
                'read_product_page' => false,
                'max_pages' => 1,
                'listings' => [],
                'price_from' => 'retail',
                'columns' => [
                    'key' => 'C', 'category' => 'A', 'model' => 'B',
                    'description' => 'D', 'retail' => 'E', 'stock' => 'H',
                ],
            ],
        ]);

        SupplierStock::create([
            'supplier_id' => $this->supplier->id,
            'external_id' => '6939924857572',
            'quantity' => 4,
            'cost_price' => 120,
            'data' => [
                'model' => 'FS40-21MR',
                'category' => 'წვრილი ტექნიკა',
                'description' => 'ვენტილატორი',
                'retail' => '149',
            ],
            'synced_at' => now(),
        ]);
    }

    protected function driver(): MideaDriver
    {
        return new MideaDriver($this->supplier->fresh());
    }

    protected function payload()
    {
        return $this->driver()->fetch('6939924857572');
    }

    /** The shop's search endpoint, answering as it really does. */
    protected function fakeSearch(string $modelNumber = 'FS40-21MR'): void
    {
        Http::fake(['*' => Http::response([
            'status' => 1,
            'products' => [[
                'id' => '431',
                'description_title' => 'ვენტილატორი',
                'title' => 'ვენტილატორი FS40-21MR',
                'short_text' => 'სიმძლავრე: 40 W',
                'text' => 'სიმძლავრე: 40 W<br />სიჩქარე: 3',
                'model_number' => $modelNumber,
                'price' => '149',
                'sale_price' => '0',
                'image' => 'main.jpg',
                'gallery' => '["gallery-1.jpg","gallery-2.jpg"]',
                'in_stock' => '1',
                'active' => '1',
                'weight' => '25',
                'link' => 'https://www.midea.ge/ka//product/ventilatori-fs40-21mr/431',
            ]],
        ], 200)]);
    }
}
