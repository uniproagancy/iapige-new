<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\Cart;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One price, wherever it is asked for.
 *
 * The shop had two answers. The deals rail worked out a campaign price and
 * advertised it; the cart, the checkout and the order read products.price and
 * knew nothing about campaigns — so a customer was shown one figure and
 * charged another. These pin the three to the same number.
 */
class PromoPriceTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected Promotion $promotion;

    /* ------------------------------------------------------------------ the rule */

    public function test_a_fixed_campaign_price_is_what_sells(): void
    {
        $product = $this->inCampaign($this->product(1000), ['promo_price' => 800]);

        $this->assertSame(800.0, $product->sellingPrice());
    }

    public function test_a_percentage_is_what_sells(): void
    {
        $product = $this->inCampaign($this->product(1000), ['discount_percent' => 25]);

        $this->assertSame(750.0, $product->sellingPrice());
    }

    /**
     * The common case: a product already marked down, simply placed on the
     * campaign shelf without a further cut.
     */
    public function test_an_already_discounted_product_keeps_its_own_price(): void
    {
        $product = $this->product(800, old: 1000);
        $this->inCampaign($product, []);

        $this->assertSame(800.0, $product->fresh()->sellingPrice());
        $this->assertSame(20, $product->discountPercent());
    }

    /** A figure that is not below the shelf price is no offer. */
    public function test_a_campaign_price_above_the_shelf_price_is_ignored(): void
    {
        $product = $this->inCampaign($this->product(1000), ['promo_price' => 1200]);

        $this->assertSame(1000.0, $product->sellingPrice());
    }

    /** A campaign that has ended prices nothing. */
    public function test_a_finished_campaign_does_not_price_anything(): void
    {
        $this->promotion->update(['ends_at' => now()->subDay()]);

        $product = $this->inCampaign($this->product(1000), ['promo_price' => 800]);

        $this->assertSame(1000.0, $product->sellingPrice());
    }

    public function test_a_product_in_no_campaign_sells_at_its_own_price(): void
    {
        $this->assertSame(1000.0, $this->product(1000)->sellingPrice());
    }

    /* ------------------------------------------------------------------ the cart */

    /** The bug as reported: the rail said one thing, the basket another. */
    public function test_the_cart_charges_the_campaign_price(): void
    {
        $product = $this->inCampaign($this->product(1000), ['promo_price' => 800]);

        $cart = app(Cart::class);
        $cart->add((string) $product->id);

        $line = $cart->lines()[0];

        $this->assertSame(800.0, $line['price']);
        $this->assertSame(800.0, $line['sum']);
    }

    public function test_the_stored_line_keeps_the_campaign_price(): void
    {
        $product = $this->inCampaign($this->product(1000), ['discount_percent' => 10]);

        app(Cart::class)->add((string) $product->id);

        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'price' => 900]);
    }

    /** Two of them cost twice the campaign price, not twice the shelf price. */
    public function test_quantity_multiplies_the_campaign_price(): void
    {
        $product = $this->inCampaign($this->product(1000), ['promo_price' => 800]);

        $cart = app(Cart::class);
        $cart->add((string) $product->id, 3);

        $this->assertSame(2400.0, $cart->lines()[0]['sum']);
    }

    /* ------------------------------------------------------------------ the rail */

    /** And the rail, which started all this, still agrees. */
    public function test_the_rail_and_the_cart_show_the_same_figure(): void
    {
        $product = $this->inCampaign($this->product(1000), ['promo_price' => 800]);

        $cart = app(Cart::class);
        $cart->add((string) $product->id);

        $this->assertSame(Catalog::deals()[0]['price'], $cart->lines()[0]['price']);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $this->category->saveTranslations(['ka' => ['name' => 'ტექნიკა', 'slug' => 'teqnika']]);

        $this->promotion = Promotion::create([
            'code' => 'week-deal', 'type' => 'deal', 'is_active' => true, 'sort_order' => 1,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);

        Catalog::flushTree();
    }

    protected function inCampaign(Product $product, array $pivot): Product
    {
        $this->promotion->products()->attach($product->id, $pivot);

        Catalog::flushTree();

        return $product->fresh();
    }

    protected function product(float $price, ?float $old = null): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $this->category->id,
            'price' => $price,
            'old_price' => $old,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => 'პროდუქტი', 'slug' => 'produqti-'.$product->id]]);

        return $product->fresh();
    }
}
