<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Promotion;
use App\Services\Feeds\GoogleFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Google Merchant Center feed.
 *
 * Nearly the same document as the Facebook one, and the parts they share are
 * covered there. What is tested here is what Google insists on differently —
 * its own spellings, something to identify an item by, and a price that
 * matches the page the item leads to.
 */
class GoogleFeedTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    /* ------------------------------------------------------------------ the route */

    public function test_the_feed_is_served_as_xml(): void
    {
        $this->product('მაცივარი', 900);

        $response = $this->get('/feed/google.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->assertStringContainsString('მაცივარი', $this->body($response));
    }

    /** Two feeds, two files: one must not overwrite the other. */
    public function test_it_is_a_different_file_from_the_facebook_one(): void
    {
        $this->assertNotSame(config('feeds.google.path'), config('feeds.facebook.path'));
    }

    /* ------------------------------------------------------------------ Google's words */

    public function test_availability_is_written_the_way_google_reads_it(): void
    {
        $this->product('მარაგშია', 900);

        $feed = $this->generate();

        $this->assertStringContainsString('<g:availability>in_stock</g:availability>', $feed);
        $this->assertStringNotContainsString('in stock<', $feed);
    }

    public function test_an_empty_shelf_is_out_of_stock(): void
    {
        $this->product('ამოიწურა', 900)->update(['stock' => 0]);

        $this->assertStringContainsString('<g:availability>out_of_stock</g:availability>', $this->generate());
    }

    /** Google calls it "preorder"; Facebook calls it something else entirely. */
    public function test_a_preorder_says_preorder(): void
    {
        $this->product('წინასწარი', 900)->update(['stock' => 0, 'is_preorder' => true]);

        $this->assertStringContainsString('<g:availability>preorder</g:availability>', $this->generate());
    }

    /** A field only Facebook understands would be a warning here. */
    public function test_it_carries_nothing_facebook_specific(): void
    {
        $this->product('მაცივარი', 900);

        $this->assertStringNotContainsString('quantity_to_sell_on_facebook', $this->generate());
    }

    /* ------------------------------------------------------------------ identity */

    /**
     * A barcode is a GTIN; what the importer made up is not.
     *
     * The sku holds the supplier's barcode wherever one was given, and
     * "ZOOM-54321" is what it falls back to for a row that had none.
     */
    public function test_a_barcode_is_sent_as_a_gtin(): void
    {
        $this->product('მაცივარი', 900, sku: '6931474700000');

        $feed = $this->generate();

        $this->assertStringContainsString('<g:gtin>6931474700000</g:gtin>', $feed);
        $this->assertStringNotContainsString('<g:mpn>', $feed);
    }

    public function test_an_invented_sku_is_sent_as_a_part_number(): void
    {
        $this->product('მაცივარი', 900, sku: 'ZOOM-54321');

        $feed = $this->generate();

        $this->assertStringContainsString('<g:mpn>ZOOM-54321</g:mpn>', $feed);
        $this->assertStringNotContainsString('<g:gtin>', $feed);
    }

    /**
     * An item with nothing to identify it must say so.
     *
     * Left unsaid, Google rejects it rather than guessing.
     */
    public function test_an_item_with_no_brand_declares_that_it_has_no_identifier(): void
    {
        $this->product('უბრენდო', 900, sku: 'ZOOM-1', brand: false);

        $this->assertStringContainsString('<g:identifier_exists>no</g:identifier_exists>', $this->generate());
    }

    public function test_a_branded_item_with_a_barcode_says_nothing_of_the_sort(): void
    {
        $this->product('მაცივარი', 900, sku: '6931474700000');

        $this->assertStringNotContainsString('identifier_exists', $this->generate());
    }

    /* ------------------------------------------------------------------ prices */

    /**
     * The price Google checks is the one on the page.
     *
     * A campaign price that never reached the feed is the usual reason an
     * item is disapproved for mismatching its landing page.
     */
    public function test_a_campaign_price_is_the_sale_price(): void
    {
        $product = $this->product('ტელევიზორი', 1000);

        $promotion = Promotion::create([
            'code' => 'week-deal', 'type' => 'deal', 'is_active' => true, 'sort_order' => 1,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);

        $promotion->products()->attach($product->id, ['promo_price' => 800]);

        $feed = $this->generate();

        $this->assertStringContainsString('<g:price>1000.00 GEL</g:price>', $feed);
        $this->assertStringContainsString('<g:sale_price>800.00 GEL</g:sale_price>', $feed);
    }

    public function test_a_product_at_full_price_has_no_sale_price(): void
    {
        $this->product('მაცივარი', 900);

        $feed = $this->generate();

        $this->assertStringContainsString('<g:price>900.00 GEL</g:price>', $feed);
        $this->assertStringNotContainsString('<g:sale_price>', $feed);
    }

    /* ------------------------------------------------------------------ variants */

    /** A phone's colours are one product, not several competing ones. */
    public function test_variants_are_grouped(): void
    {
        $this->product('შავი', 900)->update(['variant_group' => 'zoommer-5318']);

        $this->assertStringContainsString('<g:item_group_id>zoommer-5318</g:item_group_id>', $this->generate());
    }

    public function test_a_product_with_no_siblings_is_not_grouped(): void
    {
        $this->product('მარტო', 900);

        $this->assertStringNotContainsString('item_group_id', $this->generate());
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $this->category->saveTranslations(['ka' => ['name' => 'ტექნიკა', 'slug' => 'teqnika']]);
    }

    protected function generate(): string
    {
        return file_get_contents(app(GoogleFeed::class)->generate());
    }

    protected function body($response): string
    {
        return $response->streamedContent() ?: $response->getContent();
    }

    protected function product(string $name, float $price, ?string $sku = null, bool $brand = true): Product
    {
        $product = Product::create([
            'sku' => $sku ?? 'SKU-'.str()->random(6),
            'category_id' => $this->category->id,
            'brand_id' => $brand ? Brand::firstOrCreate(['slug' => 'acme'], ['name' => 'Acme', 'is_active' => true])->id : null,
            'price' => $price,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => $name, 'slug' => str()->slug($name).'-'.$product->id]]);

        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'.jpg',
            'sort_order' => 0,
        ]);

        return $product->fresh();
    }
}
