<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Feeds\FacebookFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Facebook / Instagram catalogue.
 *
 * The feed itself was written and complete; nothing reached it. There was no
 * route, so Facebook had no URL to fetch, and no scheduler entry, so the file
 * would have been built once and then served unchanged for ever. These tests
 * cover the way in as much as the contents.
 */
class FacebookFeedTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

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

    /* ------------------------------------------------------------------ the route */

    /** The endpoint Facebook is given. */
    public function test_the_feed_is_served_as_xml(): void
    {
        $this->product('მაცივარი', 900);

        $response = $this->get('/feed/facebook.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->assertStringContainsString('მაცივარი', $this->body($response));
    }

    /**
     * No locale prefix.
     *
     * Facebook stores the URL it was given and refetches it for years, so a
     * {locale} prefix would freeze the catalogue to whichever language was
     * active when the address was pasted into Commerce Manager.
     */
    public function test_the_feed_is_not_behind_a_locale_prefix(): void
    {
        $this->product('ტელევიზორი', 1200);

        $this->get('/feed/facebook.xml')->assertOk();
        $this->assertSame('/feed/facebook.xml', route('feed.facebook', absolute: false));
    }

    /** Built by the scheduler, so a request serves what is already on disk. */
    public function test_the_route_serves_the_existing_file(): void
    {
        $this->product('ქონდიციონერი', 800);

        $path = app(FacebookFeed::class)->generate();
        file_put_contents($path, '<rss><channel><title>stale</title></channel></rss>');

        $this->assertStringContainsString('stale', $this->body($this->get('/feed/facebook.xml')));
    }

    /* ------------------------------------------------------------------ contents */

    /** A product with no photograph cannot be advertised. */
    public function test_a_product_without_an_image_is_skipped(): void
    {
        $this->product('სურათიანი', 700);
        $this->product('უსურათო', 700, image: false);

        $feed = $this->generate();

        $this->assertStringContainsString('სურათიანი', $feed);
        $this->assertStringNotContainsString('უსურათო', $feed);
    }

    /** Cheap accessories drain the budget without paying for themselves. */
    public function test_products_below_the_minimum_price_are_skipped(): void
    {
        config(['feeds.facebook.min_price' => 70]);

        $this->product('კაბელი', 15);
        $this->product('ყურსასმენი', 150);

        $feed = $this->generate();

        $this->assertStringNotContainsString('კაბელი', $feed);
        $this->assertStringContainsString('ყურსასმენი', $feed);
    }

    /** An advert must not land on a page a shopper cannot buy from. */
    public function test_only_active_products_are_listed(): void
    {
        $this->product('გამოქვეყნებული', 500);
        $this->product('დრაფტი', 500)->update(['status' => Product::STATUS_DRAFT]);

        $feed = $this->generate();

        $this->assertStringContainsString('გამოქვეყნებული', $feed);
        $this->assertStringNotContainsString('დრაფტი', $feed);
    }

    /** A category kept out of the feed takes its whole branch with it. */
    public function test_an_excluded_category_excludes_its_children(): void
    {
        $child = Category::create([
            'parent_id' => $this->category->id, 'is_active' => true, 'sort_order' => 1, 'in_feed' => false,
        ]);
        $child->saveTranslations(['ka' => ['name' => 'აქსესუარები', 'slug' => 'aksesuarebi']]);

        $grandchild = Category::create([
            'parent_id' => $child->id, 'is_active' => true, 'sort_order' => 1,
        ]);
        $grandchild->saveTranslations(['ka' => ['name' => 'ჩანთები', 'slug' => 'chantebi']]);

        $this->product('მთავარი პროდუქტი', 600);
        $this->product('აკრძალული', 600, category: $child);
        $this->product('შვილიშვილი', 600, category: $grandchild);

        $feed = $this->generate();

        $this->assertStringContainsString('მთავარი პროდუქტი', $feed);
        $this->assertStringNotContainsString('აკრძალული', $feed);
        $this->assertStringNotContainsString('შვილიშვილი', $feed);
    }

    /** Out of stock is still advertised — Facebook wants the status, not silence. */
    public function test_stock_becomes_an_availability_status(): void
    {
        $this->product('მარაგშია', 400);
        $this->product('ამოიწურა', 400)->update(['stock' => 0]);

        $feed = $this->generate();

        $this->assertStringContainsString('in stock', $feed);
        $this->assertStringContainsString('out of stock', $feed);
    }

    /** The sale price goes in its own element, never over the list price. */
    public function test_a_discount_is_written_as_a_sale_price(): void
    {
        $this->product('ფასდაკლებული', 800)->update(['old_price' => 1000]);

        $feed = $this->generate();

        $this->assertStringContainsString('<g:price>1000.00 GEL</g:price>', $feed);
        $this->assertStringContainsString('<g:sale_price>800.00 GEL</g:sale_price>', $feed);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function generate(): string
    {
        return file_get_contents(app(FacebookFeed::class)->generate());
    }

    protected function body(TestResponse $response): string
    {
        return $response->streamedContent() ?: $response->getContent();
    }

    protected function product(string $name, float $price, bool $image = true, ?Category $category = null): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => ($category ?? $this->category)->id,
            'price' => $price,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => $name, 'slug' => str()->slug($name).'-'.$product->id]]);

        if ($image) {
            ProductImage::create([
                'product_id' => $product->id,
                'path' => 'products/'.$product->id.'.jpg',
                'sort_order' => 0,
            ]);
        }

        return $product->fresh();
    }
}
