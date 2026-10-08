<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\Cart;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /* ------------------------------------------------------------------ listings */

    /**
     * The shop's own discount, shown everywhere the product appears.
     *
     * A product the supplier never marked down, placed in a campaign, was
     * advertised at the campaign price on the front rail and at the full price
     * in its category, in search and among related products.
     */
    public function test_a_campaign_price_shows_in_an_ordinary_listing(): void
    {
        $product = $this->inCampaign($this->product(1000), ['promo_price' => 800]);

        $card = Catalog::card($product);

        $this->assertSame(800.0, $card['price']);
        $this->assertSame(1000.0, $card['old']);
        $this->assertSame('−20%', $card['discount']);
        $this->assertSame('sale', $card['tag']);
    }

    /** The instalment figure follows the price actually charged. */
    public function test_the_monthly_figure_follows_the_campaign_price(): void
    {
        $product = $this->inCampaign($this->product(1200), ['promo_price' => 600]);

        $this->assertSame(50, Catalog::card($product)['monthly']);
    }

    /** With no campaign, the supplier's own discount is what shows. */
    public function test_a_supplier_discount_still_shows_without_a_campaign(): void
    {
        $card = Catalog::card($this->product(800, old: 1000));

        $this->assertSame(800.0, $card['price']);
        $this->assertSame(1000.0, $card['old']);
        $this->assertSame('−20%', $card['discount']);
    }

    /** The two never both apply, so there is nothing to conflict. */
    public function test_a_campaign_replaces_the_supplier_discount(): void
    {
        $product = $this->inCampaign($this->product(800, old: 1000), ['promo_price' => 700]);

        $card = Catalog::card($product);

        $this->assertSame(700.0, $card['price']);
        $this->assertSame(800.0, $card['old'], 'the struck price is what it was selling at');
    }

    /**
     * One query for the campaigns, not one per product.
     *
     * Every card asks whether its product is on offer, so without the eager
     * load a category page of forty products would ask the database forty
     * extra times.
     */
    public function test_a_listing_does_not_ask_per_product(): void
    {
        $this->category->update(['show_on_home' => true]);

        // what matters is not the number but whether it grows with the rows
        $three = $this->queriesForListing(3);
        $ten = $this->queriesForListing(12);

        /*
         * Not equal — the second reading is lower, because the languages and
         * the interface strings are cached by then. What must hold is that
         * four times the products costs no more queries.
         */
        $this->assertLessThanOrEqual(
            $three,
            $ten,
            "a listing queried {$three} times for three products and {$ten} for twelve",
        );
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

    /** Builds a fresh listing of $count campaign products and counts the queries. */
    protected function queriesForListing(int $count): int
    {
        Product::query()->forceDelete();

        foreach (range(1, $count) as $i) {
            $this->inCampaign($this->product(1000), ['promo_price' => 800]);
        }

        Catalog::flushTree();

        // the log accumulates across enable/disable, so the second reading
        // would otherwise carry the first measurement with it
        DB::flushQueryLog();
        DB::enableQueryLog();
        $cards = Catalog::sections()[0]['products'];
        DB::disableQueryLog();

        $this->assertCount($count, $cards);
        $this->assertSame(800.0, $cards[0]['price']);

        return count(DB::getQueryLog());
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
