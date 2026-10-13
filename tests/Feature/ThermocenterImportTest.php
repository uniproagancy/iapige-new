<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Thermocenter\ThermocenterClient;
use App\Services\Import\Drivers\Thermocenter\ThermocenterDriver;
use Database\Seeders\ThermocenterSupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Thermocenter supplier.
 *
 * A CS-Cart shop, and the only supplier whose price list carries neither a
 * price nor a quantity — both come off the page. Two of its three figures are
 * not in the structured data at all, so what is pinned here is the reading of
 * the markup around it: the pre-discount price and the category trail.
 */
class ThermocenterImportTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ the page */

    public function test_the_page_is_read_from_its_structured_data(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $page = $this->client()->page('https://thermocenter.ge/kondicioneri.html');

        $this->assertSame('კონდიციონერი Gree Amber 12', $page['name']);
        $this->assertSame('GR-AMB-12', $page['sku']);
        $this->assertSame('Gree', $page['brand']);
        $this->assertSame(1850.0, $page['price']);
        $this->assertTrue($page['in_stock']);
        $this->assertSame(['https://thermocenter.ge/img/a.jpg'], $page['images']);
        $this->assertSame('39214', $page['product_id']);
    }

    public function test_a_page_with_no_product_data_gives_nothing(): void
    {
        Http::fake(['*' => Http::response('<html><body>nothing</body></html>', 200)]);

        $this->assertNull($this->client()->page('https://thermocenter.ge/x'));
    }

    public function test_a_missing_page_gives_nothing(): void
    {
        Http::fake(['*' => Http::response('gone', 404)]);

        $this->assertNull($this->client()->page('https://thermocenter.ge/x'));

        // a refused address is the answer; it is not asked three times over
        Http::assertSentCount(1);
    }

    /* ------------------------------------------------------------------ the old price */

    /**
     * The discount is only visible in the markup.
     *
     * The structured data carries today's price alone, so without reading the
     * span a discounted product arrives with nothing to strike through and the
     * saving never reaches the customer.
     */
    public function test_the_pre_discount_price_is_read_from_the_markup(): void
    {
        Http::fake(['*' => Http::response($this->html(listPrice: '2,150.00 ₾'), 200)]);

        $this->assertSame(2150.0, $this->client()->page('https://thermocenter.ge/x')['old_price']);
    }

    /**
     * The span is rendered whether or not there is a discount.
     *
     * Taken at face value, every product in the shop would show a
     * strike-through of its own price.
     */
    public function test_a_span_holding_todays_price_is_not_a_discount(): void
    {
        Http::fake(['*' => Http::response($this->html(listPrice: '1850.00 ₾'), 200)]);

        $this->assertNull($this->client()->page('https://thermocenter.ge/x')['old_price']);
    }

    public function test_a_lower_span_is_not_a_discount_either(): void
    {
        Http::fake(['*' => Http::response($this->html(listPrice: '1200.00 ₾'), 200)]);

        $this->assertNull($this->client()->page('https://thermocenter.ge/x')['old_price']);
    }

    public function test_a_page_with_no_span_has_no_old_price(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $this->assertNull($this->client()->page('https://thermocenter.ge/x')['old_price']);
    }

    /* ------------------------------------------------------------------ the category */

    /** The narrowest step of the trail is the one worth mapping. */
    public function test_the_category_comes_from_the_breadcrumb(): void
    {
        Http::fake(['*' => Http::response($this->html(
            crumbs: ['მთავარი', 'კლიმატური ტექნიკა', 'კონდიციონერები'],
        ), 200)]);

        $this->assertSame('კონდიციონერები', $this->client()->page('https://thermocenter.ge/x')['category']);
    }

    /** Linked straight off the front page: there is no category to report. */
    public function test_a_trail_with_only_the_front_page_gives_null(): void
    {
        Http::fake(['*' => Http::response($this->html(crumbs: ['მთავარი']), 200)]);

        $this->assertNull($this->client()->page('https://thermocenter.ge/x')['category']);
    }

    public function test_a_page_with_no_breadcrumb_gives_null(): void
    {
        Http::fake(['*' => Http::response($this->html(crumbs: []), 200)]);

        $this->assertNull($this->client()->page('https://thermocenter.ge/x')['category']);
    }

    /* ------------------------------------------------------------------ the driver */

    public function test_the_payload_is_built_from_the_page(): void
    {
        Http::fake(['*' => Http::response($this->html(listPrice: '2150.00 ₾'), 200)]);

        $this->row('T-1', 'https://thermocenter.ge/kondicioneri.html');

        $payload = $this->driver()->fetch('T-1');

        $this->assertSame('T-1', $payload->externalId);
        $this->assertSame(1850.0, $payload->costPrice);
        $this->assertSame(2150.0, $payload->oldCostPrice);
        $this->assertSame('Gree', $payload->brandName);
        $this->assertSame('კონდიციონერები', $payload->categoryName);
        $this->assertSame('კონდიციონერი Gree Amber 12', $payload->translations['ka']['name']);
    }

    /** Their code where the page publishes one, so two sheets cannot duplicate a product. */
    public function test_the_sku_prefers_the_suppliers_own_code(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $this->row('T-1', 'https://thermocenter.ge/x');

        $this->assertSame('thermocenter-GR-AMB-12', $this->driver()->fetch('T-1')->sku);
    }

    public function test_the_sku_falls_back_to_the_price_lists_code(): void
    {
        Http::fake(['*' => Http::response($this->html(sku: null), 200)]);

        $this->row('T-1', 'https://thermocenter.ge/x');

        $this->assertSame('thermocenter-T-1', $this->driver()->fetch('T-1')->sku);
    }

    /** The file has no quantity column, so a row it names counts as one available. */
    public function test_a_row_in_the_file_counts_as_one_available(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $this->row('T-1', 'https://thermocenter.ge/x');

        $this->assertSame(1, $this->driver()->fetch('T-1')->stock);
    }

    public function test_a_sold_out_page_is_out_of_stock(): void
    {
        Http::fake(['*' => Http::response($this->html(availability: 'https://schema.org/OutOfStock'), 200)]);

        $this->row('T-1', 'https://thermocenter.ge/x');

        $this->assertSame(0, $this->driver()->fetch('T-1')->stock);
    }

    /**
     * A code the latest file stopped naming is gone.
     *
     * Its page may well still say "in stock" — the supplier has simply stopped
     * offering it to us, and the loader zeroes whatever a new file omits.
     */
    public function test_a_code_dropped_from_the_file_is_out_of_stock(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $this->row('T-1', 'https://thermocenter.ge/x')->update(['quantity' => 0]);

        $this->assertSame(0, $this->driver()->fetch('T-1')->stock);
    }

    public function test_a_row_with_no_link_is_skipped(): void
    {
        Http::fake(['*' => Http::response($this->html(), 200)]);

        $this->row('T-1', null);

        $this->assertNull($this->driver()->fetch('T-1'));
        Http::assertNothingSent();
    }

    public function test_their_shop_is_written_out_of_our_copy(): void
    {
        Http::fake(['*' => Http::response($this->html(description: 'იხილეთ thermocenter.ge-ზე'), 200)]);

        $this->row('T-1', 'https://thermocenter.ge/x');

        $this->assertSame('იხილეთ iapi.ge-ზე', $this->driver()->fetch('T-1')->translations['ka']['description']);
    }

    /* ------------------------------------------------------------------ the floor */

    public function test_the_supplier_carries_a_floor_and_a_fifth_on_top(): void
    {
        $this->assertSame(100.0, (float) $this->supplier->config['min_cost']);
        $this->assertSame(2220.0, $this->supplier->markup(1850));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        (new ThermocenterSupplierSeeder)->run();

        $this->supplier = Supplier::where('code', 'thermocenter')->firstOrFail();
    }

    protected function client(): ThermocenterClient
    {
        return new ThermocenterClient;
    }

    protected function driver(): ThermocenterDriver
    {
        return new ThermocenterDriver($this->supplier->fresh());
    }

    protected function row(string $id, ?string $url): SupplierStock
    {
        return SupplierStock::create([
            'supplier_id' => $this->supplier->id,
            'external_id' => $id,
            'quantity' => 1,
            'cost_price' => null,
            'data' => $url ? ['url' => $url] : [],
            'synced_at' => now(),
        ]);
    }

    /** @param  array<int, string>  $crumbs */
    protected function html(
        ?string $sku = 'GR-AMB-12',
        ?string $description = 'ინვერტორული კონდიციონერი',
        float $price = 1850,
        string $availability = 'https://schema.org/InStock',
        ?string $listPrice = null,
        array $crumbs = ['მთავარი', 'კლიმატური ტექნიკა', 'კონდიციონერები'],
    ): string {
        $product = array_filter([
            '@type' => 'Product',
            'name' => 'კონდიციონერი Gree Amber 12',
            'sku' => $sku,
            'description' => $description,
            'brand' => ['name' => 'Gree'],
            'image' => ['https://thermocenter.ge/img/a.jpg'],
            'offers' => ['@type' => 'Offer', 'price' => $price, 'availability' => $availability],
        ], fn ($value) => $value !== null);

        $trail = '';

        foreach ($crumbs as $crumb) {
            $trail .= '<a href="/x" class="ty-breadcrumbs__a"><bdi>'.$crumb.'</bdi></a>';
        }

        $span = $listPrice === null
            ? ''
            : '<span id="sec_list_price_39214" class="ty-list-price">'.$listPrice.'</span>';

        return '<html><head>'
            .'<script type="application/ld+json">'.json_encode($product, JSON_UNESCAPED_UNICODE).'</script>'
            .'</head><body>'
            .'<div class="ty-breadcrumbs">'.$trail.'</div>'
            .$span
            .'<form action="/cart?product_id=39214"></form>'
            .'</body></html>';
    }
}
