<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Cart;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An empty shelf keeps a product out of the listings.
 *
 * Stock was read nowhere on the storefront: a product with none appeared in
 * its category, counted towards the category's total, said "in stock" on its
 * own page and could be bought. The "not available" line was written, styled
 * and translated, and no active product could ever reach it.
 *
 * Pre-orders are the exception throughout. They have no stock by definition
 * and exist to be seen before they arrive.
 */
class OutOfStockVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    /* ------------------------------------------------------------------ listings */

    public function test_a_product_with_no_stock_is_not_listed(): void
    {
        $this->product('მარაგშია', 5);
        $this->product('ამოიწურა', 0);

        $names = array_column(Catalog::sections()[0]['products'], 'name');

        $this->assertSame(['მარაგშია'], $names);
    }

    /** A pre-order has no stock and belongs on the page all the same. */
    public function test_a_preorder_is_listed_without_stock(): void
    {
        $this->product('წინასწარი', 0)->update(['is_preorder' => true]);

        $this->assertCount(1, Catalog::sections()[0]['products']);
    }

    /** The number beside a category has to mean the same thing as its contents. */
    public function test_the_category_count_leaves_out_what_is_gone(): void
    {
        $this->product('ერთი', 5);
        $this->product('ორი', 0);

        $section = Catalog::sections()[0];

        $this->assertCount(1, $section['products']);
        $this->assertStringContainsString('1', $section['count']);
    }

    /** A campaign cannot advertise what the shop does not have either. */
    public function test_a_campaign_does_not_show_what_is_gone(): void
    {
        $promotion = Promotion::create([
            'code' => 'week-deal', 'type' => 'deal', 'is_active' => true, 'sort_order' => 1,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);

        $promotion->products()->attach($this->product('ამოიწურა', 0)->id, ['promo_price' => 800]);

        $this->assertSame([], Catalog::deals());
    }

    /* ------------------------------------------------------------------ the page */

    /**
     * The page still opens.
     *
     * A link that has been shared, bookmarked or indexed should say the
     * product is unavailable, not answer with a 404 — so this is deliberately
     * not part of active().
     */
    public function test_the_product_page_still_opens(): void
    {
        $product = $this->product('ამოიწურა', 0);

        $this->assertNotNull(Catalog::product($product->slug));
    }

    /** And it says so, which it could not before. */
    public function test_the_page_reports_it_as_unavailable(): void
    {
        $this->assertFalse($this->product('ამოიწურა', 0)->isListed());
        $this->assertTrue($this->product('მარაგშია', 5)->isListed());
    }

    public function test_a_preorder_still_reads_as_available(): void
    {
        $preorder = $this->product('წინასწარი', 0);
        $preorder->update(['is_preorder' => true]);

        $this->assertTrue($preorder->fresh()->isListed());
    }

    /* ------------------------------------------------------------------ the basket */

    /** Taking money for it was the part that mattered. */
    public function test_it_cannot_be_added_to_the_basket(): void
    {
        $product = $this->product('ამოიწურა', 0);

        $this->assertFalse(app(Cart::class)->add((string) $product->id));
        $this->assertSame([], app(Cart::class)->lines());
    }

    public function test_a_product_in_stock_can_still_be_added(): void
    {
        $product = $this->product('მარაგშია', 5);

        $this->assertTrue(app(Cart::class)->add((string) $product->id));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->category = Category::create([
            'parent_id' => null, 'is_active' => true, 'sort_order' => 1, 'show_on_home' => true,
        ]);
        $this->category->saveTranslations(['ka' => ['name' => 'ტექნიკა', 'slug' => 'teqnika']]);

        $this->actingAs(User::factory()->create());

        Catalog::flushTree();
    }

    protected function product(string $name, int $stock): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $this->category->id,
            'price' => 1000,
            'stock' => $stock,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => $name, 'slug' => str()->slug($name).'-'.$product->id]]);

        Catalog::flushTree();

        return $product->fresh();
    }
}
