<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductInteraction;
use App\Models\User;
use App\Services\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adding to the wishlist threw every single time.
 *
 * The service names ProductInteraction::WISH_ADD and never imported it, so
 * inside `namespace App\Services` the bare name meant
 * App\Services\ProductInteraction — a class that does not exist. PHP says
 * nothing about that until the line runs, and the line only runs when
 * somebody clicks the heart.
 *
 * That is the shape worth remembering: a missing `use` is not a syntax
 * error, it is a fatal error waiting on a branch. The tests here exist
 * because nothing else ran this path at all.
 */
class WishlistTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    /* ------------------------------------------------------------------ the heart */

    public function test_a_product_is_added(): void
    {
        $this->actingAs($this->customer());

        $this->assertTrue($this->wishlist()->toggle($this->product->id));
        $this->assertTrue($this->wishlist()->has($this->product->id));
    }

    public function test_pressing_it_again_takes_it_out(): void
    {
        $this->actingAs($this->customer());
        $wishlist = $this->wishlist();

        $wishlist->toggle($this->product->id);

        $this->assertFalse($wishlist->toggle($this->product->id));
        $this->assertFalse($wishlist->has($this->product->id));
    }

    public function test_the_ids_come_back(): void
    {
        $this->actingAs($this->customer());

        $this->wishlist()->toggle($this->product->id);

        $this->assertSame([$this->product->id], $this->wishlist()->ids());
        $this->assertSame(1, $this->wishlist()->count());
    }

    /** The heart asks a guest to sign in rather than failing at them. */
    public function test_a_guest_is_turned_away_quietly(): void
    {
        $wishlist = $this->wishlist();

        $this->assertFalse($wishlist->available());
        $this->assertFalse($wishlist->toggle($this->product->id));
        $this->assertSame([], $wishlist->ids());
        $this->assertSame(0, $wishlist->count());
    }

    /** The browser sends an id as a string, which is what the heart passes on. */
    public function test_a_string_id_works_too(): void
    {
        $this->actingAs($this->customer());

        $this->assertTrue($this->wishlist()->toggle((string) $this->product->id));
    }

    /* ------------------------------------------------------------------ what it records */

    /**
     * The line the missing import was on.
     *
     * Asserting the interaction was written is the only way this test could
     * have caught the bug: toggling worked right up to the moment it tried
     * to name the class.
     */
    public function test_adding_is_recorded(): void
    {
        $this->actingAs($this->customer());

        $this->wishlist()->toggle($this->product->id);

        $this->assertDatabaseHas('product_interactions', [
            'product_id' => $this->product->id,
            'action' => ProductInteraction::WISH_ADD,
        ]);
    }

    public function test_removing_is_recorded_too(): void
    {
        $this->actingAs($this->customer());

        $this->wishlist()->toggle($this->product->id);
        $this->wishlist()->toggle($this->product->id);

        $this->assertDatabaseHas('product_interactions', [
            'product_id' => $this->product->id,
            'action' => ProductInteraction::WISH_REMOVE,
        ]);
    }

    /** It is recorded against whoever pressed it. */
    public function test_the_record_names_the_customer(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer);

        $this->wishlist()->toggle($this->product->id);

        $this->assertDatabaseHas('product_interactions', [
            'user_id' => $customer->id,
            'action' => ProductInteraction::WISH_ADD,
        ]);
    }

    /** With no price given, the product's own is written down. */
    public function test_the_price_is_taken_from_the_product(): void
    {
        $this->actingAs($this->customer());

        $this->wishlist()->toggle($this->product->id);

        $recorded = ProductInteraction::where('product_id', $this->product->id)->value('price');

        $this->assertSame(300.0, (float) $recorded);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        $this->product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $category->id,
            'price' => 300,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);
    }

    protected function customer(): User
    {
        return User::create([
            'name' => 'გიორგი',
            'email' => 'c'.uniqid().'@iapi.ge',
            'password' => bcrypt('x'),
        ]);
    }

    protected function wishlist(): Wishlist
    {
        return app(Wishlist::class);
    }
}
