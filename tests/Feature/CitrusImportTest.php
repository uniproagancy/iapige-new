<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Citrus\CitrusClient;
use App\Services\Import\Drivers\Citrus\CitrusDriver;
use App\Services\Import\Drivers\Citrus\CitrusLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Citrus, added by pasting addresses.
 *
 * The site is a JavaScript shell — fetching a product page gives a loader and
 * no product — but everything it draws comes from /api/product/{slug}, which
 * answers with the name, both prices, the stock, the brand, the gallery and
 * the specification list. So nothing is scraped.
 *
 * There is no price list, which is the point: somebody finds the products
 * they want and pastes their addresses, all of them at once, and the import
 * runs over them like any other supplier's rows.
 */
class CitrusImportTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ reading an address */

    public function test_a_pasted_address_reduces_to_its_slug(): void
    {
        $client = new CitrusClient;

        foreach ([
            'https://citrus.ge/product/gamtsovi-marazzi-msbhd-60fs370wg',
            'https://citrus.ge/product/gamtsovi-marazzi-msbhd-60fs370wg/',
            'https://citrus.ge/ka/product/gamtsovi-marazzi-msbhd-60fs370wg?utm_source=x',
            'gamtsovi-marazzi-msbhd-60fs370wg',
        ] as $address) {
            $this->assertSame('gamtsovi-marazzi-msbhd-60fs370wg', $client->slug($address), $address);
        }
    }

    public function test_nothing_is_not_a_slug(): void
    {
        $this->assertNull((new CitrusClient)->slug('   '));
    }

    /* ------------------------------------------------------------------ the product */

    public function test_the_product_comes_from_the_api(): void
    {
        $this->fakeApi();

        $payload = $this->payload();

        $this->assertNotNull($payload);
        $this->assertSame('citrus-CS403613', $payload->sku);
        $this->assertSame('გაზის წყლის გამაცხელებელი MIDEA MGWH11-22EGPCIS(W)', $payload->translations['ka']['name']);
        $this->assertSame('MIDEA', $payload->brandName);
        $this->assertSame(699.0, $payload->costPrice);
        $this->assertSame(899.0, $payload->oldCostPrice);
        $this->assertSame(99, $payload->stock);
    }

    /** The narrowest step of the breadcrumb, which is what an admin maps by. */
    public function test_the_category_comes_from_the_breadcrumb(): void
    {
        $this->fakeApi();

        $this->assertSame('წყლის გამაცხელებელი', $this->payload()->categoryName);
    }

    public function test_the_gallery_comes_with_it(): void
    {
        $this->fakeApi();

        $images = $this->payload()->images;

        $this->assertContains('https://media.citrus.ge/images/products/CS403613/1.jpg', $images);
        $this->assertContains('https://media.citrus.ge/images/products/CS403613/second.jpg', $images);
        $this->assertSame($images, array_unique($images), 'the main photograph must not be listed twice');
    }

    /**
     * The site marks what it filters its own catalogue by.
     *
     * Which is a better guess at what is worth filtering on than anything we
     * could infer from the value.
     */
    public function test_the_specifications_keep_what_is_filterable(): void
    {
        $this->fakeApi();

        $specs = collect($this->payload()->specs)->keyBy('name');

        $this->assertTrue($specs['ბრენდი']['filterable']);
        $this->assertFalse($specs['მოდელი']['filterable']);
    }

    /** A warranty is a specification too, and it arrives apart from them. */
    public function test_the_warranty_becomes_a_specification(): void
    {
        $this->fakeApi();

        $this->assertContains(
            '2 წელი',
            collect($this->payload()->specs)->pluck('value')->all(),
        );
    }

    public function test_the_weight_is_read_in_grams(): void
    {
        $this->fakeApi();

        $this->assertSame(12500, $this->payload()->weight);
    }

    /* ------------------------------------------------------------------ stock and price */

    public function test_a_product_that_is_not_sold_is_out_of_stock(): void
    {
        $this->fakeApi(stockStatus: 0);

        $this->assertSame(0, $this->payload()->stock);
    }

    /** old_price below the price is not a previous price. */
    public function test_a_lower_old_price_is_not_a_discount(): void
    {
        $this->fakeApi(oldPrice: 500);

        $this->assertNull($this->payload()->oldCostPrice);
    }

    public function test_a_product_priced_at_nothing_is_skipped(): void
    {
        $this->fakeApi(price: 0);

        $this->assertNull($this->payload());
    }

    public function test_an_address_the_api_does_not_know_is_skipped(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->assertNull($this->payload());
    }

    /* ------------------------------------------------------------------ the price */

    /**
     * Citrus is here for the content, not the price.
     *
     * The name, the description, the specifications and the photographs come
     * from them; the price is set by hand afterwards. A markup would quietly
     * put a figure on the shelf that nobody chose.
     */
    public function test_no_margin_is_added(): void
    {
        $this->assertSame([], $this->supplier->markup ?? []);
        $this->assertSame(699.0, $this->supplier->markup(699.0));
    }
    /* ------------------------------------------------------------------ pasting a block */

    public function test_several_addresses_are_added_at_once(): void
    {
        $result = $this->paste("https://citrus.ge/product/one\nhttps://citrus.ge/product/two\nhttps://citrus.ge/product/three");

        $this->assertSame(3, $result['added']);
        $this->assertSame(3, SupplierStock::where('supplier_id', $this->supplier->id)->count());
    }

    /** Pasted from a spreadsheet column, a chat message or the address bar. */
    public function test_commas_and_spaces_separate_them_too(): void
    {
        $result = $this->paste('https://citrus.ge/product/one, https://citrus.ge/product/two');

        $this->assertSame(2, $result['added']);
    }

    public function test_the_same_address_twice_is_one_row(): void
    {
        $result = $this->paste("https://citrus.ge/product/one\nhttps://citrus.ge/product/one");

        $this->assertSame(1, $result['added']);
    }

    /** Adding a product already on the list updates it rather than failing. */
    public function test_an_address_already_added_is_counted_as_an_update(): void
    {
        $this->paste('https://citrus.ge/product/one');

        $result = $this->paste('https://citrus.ge/product/one');

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['updated']);
    }

    /**
     * Another shop's address is refused.
     *
     * The API would answer for a slug that happens to collide, and the wrong
     * product would arrive wearing the right name.
     */
    public function test_another_shops_address_is_refused(): void
    {
        $result = $this->paste("https://citrus.ge/product/one\nhttps://zoommer.ge/product/two");

        $this->assertSame(1, $result['added']);
        $this->assertSame(['https://zoommer.ge/product/two'], $result['skipped']);
    }

    /** A category typed on the form beats the site's own. */
    public function test_a_typed_category_is_remembered(): void
    {
        $this->paste('https://citrus.ge/product/one', 'ჩემი კატეგორია');

        $row = SupplierStock::where('external_id', 'one')->first();

        $this->assertSame('ჩემი კატეგორია', $row->data['category']);
    }

    public function test_a_typed_category_wins_over_the_breadcrumb(): void
    {
        $this->fakeApi();
        $this->paste('https://citrus.ge/product/gazis-tsylis-gamatskhelebeli', 'ჩემი კატეგორია');

        $payload = (new CitrusDriver($this->supplier->fresh()))->fetch('gazis-tsylis-gamatskhelebeli');

        $this->assertSame('ჩემი კატეგორია', $payload->categoryName);
    }

    /** Every pasted row is asked about, however many there are. */
    public function test_the_run_covers_everything_pasted(): void
    {
        $this->paste("https://citrus.ge/product/one\nhttps://citrus.ge/product/two");

        $ids = collect((new CitrusDriver($this->supplier->fresh()))->ids())->all();

        $this->assertSame(['one', 'two'], $ids);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'code' => 'citrus',
            'name' => 'Citrus',
            'driver' => CitrusDriver::class,
            'is_active' => true,
            'priority' => 70,
            'markup' => [],
            'config' => ['locale' => 'ka', 'replace' => ['citrus.ge' => 'iapi.ge']],
        ]);
    }

    /** @return array{added:int, updated:int, skipped:array<int, string>} */
    protected function paste(string $block, ?string $category = null): array
    {
        return app(CitrusLinks::class)->add($this->supplier, $block, $category);
    }

    protected function payload()
    {
        SupplierStock::updateOrCreate(
            ['supplier_id' => $this->supplier->id, 'external_id' => 'gazis-tsylis-gamatskhelebeli'],
            ['quantity' => 1, 'data' => ['slug' => 'gazis-tsylis-gamatskhelebeli'], 'synced_at' => now()],
        );

        return (new CitrusDriver($this->supplier->fresh()))->fetch('gazis-tsylis-gamatskhelebeli');
    }

    /** The endpoint, answering as it really does. */
    protected function fakeApi(float $price = 699, float $oldPrice = 899, int $stockStatus = 1): void
    {
        Http::fake(['*' => Http::response([
            'id' => 87328,
            'sku' => 'CS403613',
            'name' => 'გაზის წყლის გამაცხელებელი MIDEA MGWH11-22EGPCIS(W)',
            'slug' => 'gazis-tsylis-gamatskhelebeli',
            'price' => $price,
            'old_price' => $oldPrice,
            'stock' => 99,
            'stock_status' => $stockStatus,
            'manufacturer' => ['name' => 'MIDEA', 'slug' => 'midea'],
            'image' => 'https://media.citrus.ge/images/products/CS403613/1.jpg',
            'large_images' => [
                'https://media.citrus.ge/images/products/CS403613/1.jpg',
                'https://media.citrus.ge/images/products/CS403613/second.jpg',
            ],
            'images' => [
                ['url' => 'https://media.citrus.ge/images/products/CS403613/1.jpg', 'color_id' => null],
            ],
            'details' => [
                'weight' => '12.5',
                'model' => 'MGWH11-22EGPCIS(W)',
                'warranty' => '2 წელი',
                'mini_description' => 'გაზის წყლის გამათბობელი',
                'description' => 'ბრენდი: MIDEA<br />ტიპი: გაზის წყლის გამათბობელი',
            ],
            'attributes' => [
                ['id' => 190, 'name' => 'ბრენდი', 'type' => 'checkbox', 'value' => 'MIDEA'],
                ['id' => 258, 'name' => 'მოდელი', 'type' => null, 'value' => 'MGWH11-22EGPCIS(W)'],
            ],
            'breadcrumb' => [
                ['name' => 'ტექნიკა', 'slug' => 'eleqtro-teqnika'],
                ['name' => 'კლიმატური ტექნიკა', 'slug' => 'klimaturi-teqnika'],
                ['name' => 'წყლის გამაცხელებელი', 'slug' => 'cyalgamackhelebeli'],
            ],
        ], 200)]);
    }
}
