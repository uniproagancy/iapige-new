<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "From X ₾ a month" is quoted over one term, in one place.
 *
 * It was quoted over two. The cards, the product page and the cart divided by
 * twelve; both advertising feeds divided by twenty-four. So the same
 * television was advertised at one figure and priced at another the moment
 * somebody clicked through — not a rounding difference, a different claim.
 *
 * The number now lives in shop.instalment_months and every one of them reads
 * it, which is what these tests are for: not that it is 24, but that nothing
 * quotes a term of its own.
 */
class InstalmentTermTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ the figure */

    public function test_a_card_quotes_the_shop_term(): void
    {
        $product = $this->product(2400);

        $this->assertSame(100, Catalog::card($product)['monthly']);
    }

    public function test_the_term_is_twenty_four_months(): void
    {
        $this->assertSame(24, (int) config('shop.instalment_months'));
    }

    /** Changing the one number changes the figure. */
    public function test_changing_the_term_changes_the_figure(): void
    {
        $product = $this->product(2400);

        config(['shop.instalment_months' => 12]);

        $this->assertSame(200, $product->monthlyPrice());
    }

    /** A caller that names its own term still gets it. */
    public function test_an_explicit_term_still_wins(): void
    {
        $this->assertSame(200, $this->product(2400)->monthlyPrice(12));
    }

    /**
     * A term of nothing would divide by zero.
     *
     * The value comes from a configuration file somebody edits by hand, and
     * an empty line there reads as zero.
     */
    public function test_a_term_of_zero_does_not_divide_by_it(): void
    {
        config(['shop.instalment_months' => 0]);

        $this->assertSame(2400, $this->product(2400)->monthlyPrice());
    }

    /** It follows the price actually charged, not the one struck through. */
    public function test_it_follows_the_selling_price(): void
    {
        $product = $this->product(2400);
        $product->update(['old_price' => 4800]);

        $this->assertSame(100, $product->fresh()->monthlyPrice());
    }

    /* ------------------------------------------------------------------ the cart */

    public function test_the_cart_quotes_the_same_term(): void
    {
        $product = $this->product(2400);

        $this->actingAs($this->customer());

        $this->assertTrue(app(Cart::class)->add((string) $product->id, 1));
        $this->assertSame(100, app(Cart::class)->monthly());
    }

    /* ------------------------------------------------------------------ the feeds */

    /**
     * The feeds were the half that was already right, and they must not drift
     * back: they read the same setting now rather than carrying their own.
     */
    public function test_both_feeds_quote_the_same_term(): void
    {
        $this->assertSame(24, (int) config('feeds.facebook.instalment_months'));
        $this->assertSame(24, (int) config('feeds.google.instalment_months'));
    }

    /* ------------------------------------------------------------------ the labels */

    /** A label naming a different term is the same contradiction in words. */
    public function test_the_label_names_the_term_it_is_quoted_over(): void
    {
        foreach (['ka', 'en'] as $locale) {
            $this->assertStringContainsString(
                '24',
                __('product.installment', [], $locale),
                "the {$locale} label still names another term",
            );
        }
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();
    }

    protected function product(int $price): Product
    {
        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $category->id,
            'price' => $price,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => 'ტელევიზორი', 'slug' => 'tv-'.$product->id]]);

        return $product->fresh();
    }

    protected function customer(): User
    {
        return User::create([
            'name' => 'გიორგი',
            'email' => 'c'.uniqid().'@iapi.ge',
            'password' => bcrypt('x'),
        ]);
    }
}
