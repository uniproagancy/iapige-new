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
 * Midea, asked the way the working importer asked.
 *
 * One POST to the site's own search, and the product is taken from what comes
 * back: the title, the description, both prices and the photographs. The price
 * list says which models to ask about and how many are left.
 *
 * A model the site does not sell is not imported at all — which is the rule
 * that keeps a picture on every card, and the one worth pinning, because the
 * alternative fills the catalogue with spreadsheet rows waiting for
 * photographs that never come.
 */
class MideaImportTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ the product */

    public function test_the_product_comes_from_the_search(): void
    {
        $this->fakeSearch();

        $payload = $this->payload();

        $this->assertNotNull($payload);
        $this->assertSame('ვენტილატორი FS40-21MR', $payload->translations['ka']['name']);
        $this->assertStringContainsString('სიმძლავრე', $payload->translations['ka']['description']);
        $this->assertSame('Midea', $payload->brandName);
        $this->assertSame(25, $payload->weight);
    }

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

    /* ------------------------------------------------------------------ the prices */

    public function test_the_site_price_is_what_we_sell_at(): void
    {
        $this->fakeSearch();

        $payload = $this->payload();

        $this->assertSame(149.0, $payload->costPrice);
        $this->assertNull($payload->oldCostPrice);
    }

    /** A promotion sells at the lower figure and strikes the higher one through. */
    public function test_a_promotion_is_carried_over(): void
    {
        $this->fakeSearch(price: '1799', salePrice: '1449');

        $payload = $this->payload();

        $this->assertSame(1449.0, $payload->costPrice);
        $this->assertSame(1799.0, $payload->oldCostPrice);
    }

    /** The site writes 0 for "no discount", which is not a price. */
    public function test_a_zero_sale_price_is_not_a_discount(): void
    {
        $this->fakeSearch(price: '149', salePrice: '0');

        $this->assertNull($this->payload()->oldCostPrice);
    }

    /** A product the site prices at nothing falls back to the price list. */
    public function test_the_price_list_is_the_fallback(): void
    {
        $this->fakeSearch(price: '0');

        $this->assertSame(1299.0, $this->payload()->costPrice);
    }

    /* ------------------------------------------------------------------ the stock */

    /** The spreadsheet says how many; the site only says whether at all. */
    public function test_the_quantity_comes_from_the_price_list(): void
    {
        $this->fakeSearch();

        $this->assertSame(4, $this->payload()->stock);
    }

    public function test_a_sold_out_product_is_out_of_stock(): void
    {
        $this->fakeSearch(inStock: '0');

        $this->assertSame(0, $this->payload()->stock);
    }

    /* ------------------------------------------------------------------ not found */

    /**
     * A model the site does not sell is not imported.
     *
     * The rule that keeps a photograph on every card.
     */
    public function test_a_model_the_site_does_not_sell_is_skipped(): void
    {
        Http::fake(['*' => Http::response(['status' => 1, 'products' => []], 200)]);

        $this->assertNull($this->payload());
    }

    /** status 0 means it did not understand the request, not that there is nothing. */
    public function test_a_refused_request_is_not_an_empty_result(): void
    {
        Http::fake(['*' => Http::response(['status' => 0, 'products' => null], 200)]);

        $this->assertNull($this->payload());
    }

    public function test_a_site_that_times_out_imports_nothing(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $this->expectException(ConnectionException::class);

        $this->payload();
    }

    /* ------------------------------------------------------------------ the wrong product */

    /**
     * A search is a search.
     *
     * Ask it for a model and it answers with everything whose text mentions
     * it, so the answer is only taken when its own model_number matches. A
     * product wearing another product's photographs is worse than none.
     */
    public function test_a_result_for_a_different_model_is_refused(): void
    {
        $this->fakeSearch(modelNumber: 'SOMETHING-ELSE');

        $this->assertNull($this->payload());
    }

    /** The site writes the brand in front of some model numbers and not others. */
    public function test_a_model_number_carrying_the_brand_still_matches(): void
    {
        $this->fakeSearch(modelNumber: 'Midea FS40-21MR');

        $this->assertNotNull($this->payload());
    }

    /* ------------------------------------------------------------------ extra words */

    /**
     * A price list writes the model with the brand in front or a colour on the
     * end, and the search matches the whole string — so either one kills it.
     */
    public function test_a_model_written_with_the_brand_in_front_is_found(): void
    {
        $this->fakeExactSearch('FS40-21MR');
        $this->setModel('Midea FS40-21MR');

        $this->assertNotNull($this->payload());
    }

    public function test_a_model_written_with_a_suffix_is_found(): void
    {
        $this->fakeExactSearch('FS40-21MR');
        $this->setModel('FS40-21MR INOX');

        $this->assertNotNull($this->payload());
    }

    /** The warehouse's column is tried when the website's is empty. */
    public function test_the_second_model_column_is_tried(): void
    {
        $this->fakeExactSearch('FS40-21MR');

        $this->row(['site_model' => '', 'model' => 'FS40-21MR']);

        $this->assertNotNull($this->payload());
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
            'cost_price' => 1000,
            'data' => [
                'model' => 'FS40-21MR',
                'category' => 'წვრილი ტექნიკა',
                'description' => 'ვენტილატორი',
                'retail' => '1299',
            ],
            'synced_at' => now(),
        ]);
    }

    protected function payload()
    {
        return (new MideaDriver($this->supplier->fresh()))->fetch('6939924857572');
    }

    protected function setModel(string $model): void
    {
        $this->row(['site_model' => $model]);
    }

    /** @param  array<string, string>  $models */
    protected function row(array $models): void
    {
        $stock = SupplierStock::where('supplier_id', $this->supplier->id)->firstOrFail();

        $stock->update(['data' => array_merge($stock->data, $models)]);
    }

    /** A search that only answers the exact keyword, as the real one does. */
    protected function fakeExactSearch(string $answersTo): void
    {
        Http::fake(function ($request) use ($answersTo) {
            parse_str((string) $request->body(), $form);

            return ($form['keyword'] ?? null) === $answersTo
                ? Http::response($this->found(), 200)
                : Http::response(['status' => 1, 'products' => []], 200);
        });
    }

    protected function fakeSearch(
        string $modelNumber = 'FS40-21MR',
        string $price = '149',
        string $salePrice = '0',
        string $inStock = '1',
    ): void {
        Http::fake(['*' => Http::response(
            $this->found($modelNumber, $price, $salePrice, $inStock),
            200,
        )]);
    }

    /** The shop's search endpoint, answering as it really does. */
    protected function found(
        string $modelNumber = 'FS40-21MR',
        string $price = '149',
        string $salePrice = '0',
        string $inStock = '1',
    ): array {
        return [
            'status' => 1,
            'products' => [[
                'id' => '431',
                'description_title' => 'ვენტილატორი',
                'title' => 'ვენტილატორი FS40-21MR',
                'short_text' => 'სიმძლავრე: 40 W',
                'text' => 'სიმძლავრე: 40 W<br />სიჩქარე: 3',
                'model_number' => $modelNumber,
                'price' => $price,
                'sale_price' => $salePrice,
                'image' => 'main.jpg',
                'gallery' => '["gallery-1.jpg","gallery-2.jpg"]',
                'in_stock' => $inStock,
                'active' => '1',
                'weight' => '25',
                'link' => 'https://www.midea.ge/ka//product/ventilatori-fs40-21mr/431',
            ]],
        ];
    }
}
