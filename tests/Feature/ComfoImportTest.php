<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Comfo\ComfoClient;
use App\Services\Import\Drivers\Comfo\ComfoDriver;
use App\Services\Import\PriceListLoader;
use Database\Seeders\ComfoSupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Comfo supplier.
 *
 * Three columns arrive — a code, a quantity and a link — and everything else is
 * read off the linked page. What is pinned here is the part that has no
 * equivalent in the other suppliers: the same product is reachable under two
 * spellings of its address, and the price list does not always use the one the
 * site answers to.
 */
class ComfoImportTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ the second spelling */

    /**
     * A Georgian address that answers 404 is tried again in Latin.
     *
     * This is the whole reason the supplier needs a client of its own. Without
     * the retry the row is silently skipped, and a price list that loads
     * perfectly imports a fraction of what it names.
     */
    public function test_a_georgian_address_is_retried_transliterated(): void
    {
        $this->fakeLatinOnly();

        $page = (new ComfoClient)->page('https://comfo.ge/პროდუქტი/მაცივარი');

        $this->assertSame('მაცივარი LG GR-B509', $page['name']);

        // the wrong spelling is asked once, not three times over
        Http::assertSentCount(2);
    }

    /**
     * The link arrives percent-encoded as often as not.
     *
     * Encoded, there are no Georgian letters left in the string to replace, so
     * a retry built from the raw text would fetch exactly the address that
     * just answered 404.
     */
    public function test_an_encoded_georgian_address_is_retried_too(): void
    {
        $this->fakeLatinOnly();

        $page = (new ComfoClient)->page('https://comfo.ge/'.rawurlencode('პროდუქტი').'/'.rawurlencode('მაცივარი'));

        $this->assertNotNull($page);
        $this->assertSame('მაცივარი LG GR-B509', $page['name']);
    }

    /** An address with no Georgian in it is fetched once and not twice. */
    public function test_a_latin_address_is_not_fetched_twice(): void
    {
        Http::fake(['*' => Http::response('gone', 404)]);

        $this->assertNull((new ComfoClient)->page('https://comfo.ge/produkti/macivari'));

        Http::assertSentCount(1);
    }

    public function test_a_product_missing_under_both_spellings_is_skipped(): void
    {
        Http::fake(['*' => Http::response('gone', 404)]);

        $this->assertNull((new ComfoClient)->page('https://comfo.ge/პროდუქტი/მაცივარი'));
    }

    /* ------------------------------------------------------------------ the page */

    public function test_the_page_is_read_from_its_structured_data(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $page = (new ComfoClient)->page('https://comfo.ge/produkti/macivari');

        $this->assertSame('მაცივარი LG GR-B509', $page['name']);
        $this->assertSame('LG', $page['brand']);
        $this->assertSame(1299.0, $page['price']);
        $this->assertTrue($page['in_stock']);
        $this->assertSame(['https://comfo.ge/img/a.jpg', 'https://comfo.ge/img/b.jpg'], $page['images']);
        $this->assertSame([['name' => 'მოცულობა', 'value' => '384 ლ']], $page['specs']);
    }

    /** The node is found whether the type is a bare word or its full URL. */
    public function test_a_product_declared_by_url_is_still_a_product(): void
    {
        Http::fake(['*' => Http::response($this->html(type: 'https://schema.org/Product'), 200)]);

        $this->assertSame('მაცივარი LG GR-B509', (new ComfoClient)->page('https://comfo.ge/x')['name']);
    }

    public function test_a_page_with_no_product_data_gives_nothing(): void
    {
        Http::fake(['*' => Http::response('<html><body>no json here</body></html>', 200)]);

        $this->assertNull((new ComfoClient)->page('https://comfo.ge/x'));
    }

    /**
     * Something for the admin to map it by.
     *
     * The price list has no category column, so without this every Comfo
     * product would arrive uncategorised with nothing in the mapping queue —
     * exactly the hole Kontakt products once fell into.
     */
    public function test_the_category_comes_from_the_page(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $this->assertSame('მაცივრები', (new ComfoClient)->page('https://comfo.ge/x')['category']);
    }

    public function test_a_category_written_as_a_trail_yields_its_narrowest_step(): void
    {
        Http::fake(['*' => Http::response($this->html(category: 'ტექნიკა > სამზარეულო > მაცივრები'), 200)]);

        $this->assertSame('მაცივრები', (new ComfoClient)->page('https://comfo.ge/x')['category']);
    }

    public function test_the_breadcrumb_answers_when_the_product_names_no_category(): void
    {
        $crumbs = $this->ld(['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'name' => 'მთავარი'],
            ['@type' => 'ListItem', 'name' => 'ტექნიკა'],
            ['@type' => 'ListItem', 'name' => 'მაცივარი LG GR-B509'],
        ]]);

        Http::fake(['*' => Http::response($this->html(category: null, extra: $crumbs), 200)]);

        $this->assertSame('ტექნიკა', (new ComfoClient)->page('https://comfo.ge/x')['category']);
    }

    /* ------------------------------------------------------------------ the driver */

    public function test_the_payload_takes_its_price_from_the_page(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $this->row('4821', quantity: 7, url: 'https://comfo.ge/produkti/macivari');

        $payload = $this->driver()->fetch('4821');

        $this->assertSame('4821', $payload->externalId);
        $this->assertSame('comfo-4821', $payload->sku);
        $this->assertSame(1299.0, $payload->costPrice);
        $this->assertNull($payload->oldCostPrice);
        $this->assertSame('LG', $payload->brandName);
        $this->assertSame('მაცივრები', $payload->categoryName);
        $this->assertSame('მაცივარი LG GR-B509', $payload->translations['ka']['name']);
    }

    /** The spreadsheet says how many; the page only says whether at all. */
    public function test_the_quantity_comes_from_the_spreadsheet(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $this->row('4821', quantity: 7, url: 'https://comfo.ge/produkti/macivari');

        $this->assertSame(7, $this->driver()->fetch('4821')->stock);
    }

    /**
     * A page that says it is sold out overrides the sheet.
     *
     * The two sources disagree whenever the file is a day old, and selling
     * something the supplier cannot hand over costs an order.
     */
    public function test_a_sold_out_page_beats_a_stocked_spreadsheet(): void
    {
        Http::fake(['*' => Http::response($this->html(availability: 'https://schema.org/OutOfStock'), 200)]);

        $this->row('4821', quantity: 7, url: 'https://comfo.ge/produkti/macivari');

        $this->assertSame(0, $this->driver()->fetch('4821')->stock);
    }

    public function test_a_row_with_no_link_is_skipped(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $this->row('4821', quantity: 7, url: null);

        $this->assertNull($this->driver()->fetch('4821'));
        Http::assertNothingSent();
    }

    public function test_a_page_with_no_price_is_skipped(): void
    {
        Http::fake(['*' => Http::response($this->html(price: 0), 200)]);

        $this->row('4821', quantity: 7, url: 'https://comfo.ge/produkti/macivari');

        $this->assertNull($this->driver()->fetch('4821'));
    }

    public function test_their_shop_is_written_out_of_our_copy(): void
    {
        Http::fake(['*' => Http::response($this->html(
            description: 'იყიდეთ comfo.ge-ზე იაფად',
        ), 200)]);

        $this->row('4821', quantity: 7, url: 'https://comfo.ge/produkti/macivari');

        $this->assertSame('იყიდეთ iapi.ge-ზე იაფად', $this->driver()->fetch('4821')->translations['ka']['description']);
    }

    /** Only the codes in supplier_stocks are imported, so the file decides the run. */
    public function test_the_run_covers_exactly_what_the_price_list_named(): void
    {
        $this->row('4821', 7, 'https://comfo.ge/a');
        $this->row('4822', 0, 'https://comfo.ge/b');

        $this->assertSame(['4821', '4822'], collect($this->driver()->ids())->all());
    }

    /* ------------------------------------------------------------------ the spreadsheet */

    /**
     * The three columns, loaded by the shared loader.
     *
     * Comfo's file has no price column at all, which the loader has to accept
     * rather than treat as a malformed row.
     */
    public function test_the_price_list_loads_without_a_price_column(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'comfo').'.csv';
        file_put_contents($path, "id,stock,link\n4821,7,https://comfo.ge/a\n4822,0,https://comfo.ge/b\n");

        $result = (new PriceListLoader)->load($this->supplier, $path);

        unlink($path);

        $this->assertSame(2, $result['read']);

        $row = SupplierStock::where('external_id', '4821')->first();

        $this->assertSame(7, $row->quantity);
        $this->assertNull($row->cost_price);
        $this->assertSame('https://comfo.ge/a', $row->data['url']);
    }

    /* ------------------------------------------------------------------ the margin */

    /**
     * A flat margin, because a percentage earns nothing on a cheap item.
     *
     * @see Supplier::markup()
     */
    public function test_the_margin_is_flat_and_steps_at_a_hundred(): void
    {
        $this->assertSame(80.0, $this->supplier->markup(30));
        $this->assertSame(150.0, $this->supplier->markup(100));
        $this->assertSame(300.0, $this->supplier->markup(200));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        (new ComfoSupplierSeeder)->run();

        $this->supplier = Supplier::where('code', 'comfo')->firstOrFail();
    }

    protected function driver(): ComfoDriver
    {
        return new ComfoDriver($this->supplier->fresh());
    }

    protected function row(string $id, int $quantity, ?string $url): SupplierStock
    {
        return SupplierStock::create([
            'supplier_id' => $this->supplier->id,
            'external_id' => $id,
            'quantity' => $quantity,
            'cost_price' => null,
            'data' => $url ? ['url' => $url] : [],
            'synced_at' => now(),
        ]);
    }

    /**
     * The site answers only to the Latin spelling.
     *
     * Keyed on the decoded address because the client's own request is
     * percent-encoded by the time it is sent, so a pattern written in Georgian
     * would match nothing and the test would reach the real site.
     */
    protected function fakeLatinOnly(): void
    {
        Http::fake(fn ($request) => str_contains(rawurldecode($request->url()), 'produkti')
            ? Http::response($this->html(), 200)
            : Http::response('gone', 404));
    }

    protected function ld(array $data): string
    {
        return '<script type="application/ld+json">'.json_encode($data, JSON_UNESCAPED_UNICODE).'</script>';
    }

    protected function html(
        string $type = 'Product',
        ?string $category = 'მაცივრები',
        ?string $description = 'ფართო მაცივარი',
        float $price = 1299,
        string $availability = 'https://schema.org/InStock',
        string $extra = '',
    ): string {
        $product = array_filter([
            '@type' => $type,
            'name' => 'მაცივარი LG GR-B509',
            'description' => $description,
            'brand' => ['name' => 'LG'],
            'category' => $category,
            'image' => ['https://comfo.ge/img/a.jpg', 'https://comfo.ge/img/b.jpg'],
            'additionalProperty' => [
                ['@type' => 'PropertyValue', 'name' => 'მოცულობა', 'value' => '384 ლ'],
                ['@type' => 'PropertyValue', 'name' => 'ფერი', 'value' => '-'],
            ],
            'offers' => ['@type' => 'Offer', 'price' => $price, 'availability' => $availability],
        ], fn ($value) => $value !== null);

        return '<html><head>'.$this->ld($product).$extra.'</head><body></body></html>';
    }
}
