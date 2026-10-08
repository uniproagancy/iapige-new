<?php

namespace Tests\Feature;

use App\Livewire\Admin\Promotions\Index;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Putting products into the deals rail.
 *
 * The table, the pivot and the storefront query all existed; nothing could
 * write to them, so the rail on the front page had nothing to show. This is
 * the screen that fills it, and these pin the rules the storefront already
 * assumes — chiefly that a promotional price it cannot strike through is
 * dropped in silence, which made a typo look like the feature not working.
 */
class AdminPromotionsTest extends TestCase
{
    use RefreshDatabase;

    protected Promotion $promotion;

    protected Category $category;

    /* ------------------------------------------------------------------ access */

    public function test_the_page_is_behind_the_admin_gate(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/admin/promotions')
            ->assertForbidden();
    }

    public function test_an_admin_can_open_it(): void
    {
        $this->admin()->get('/admin/promotions')->assertOk();
    }

    /* ------------------------------------------------------------------ products */

    public function test_a_product_can_be_added_to_the_campaign(): void
    {
        $product = $this->product('მაცივარი', 1000);

        $this->screen()->call('add', $product->id);

        $this->assertTrue($this->promotion->products()->where('products.id', $product->id)->exists());
    }

    /** Each new one goes to the back rather than fighting for first place. */
    public function test_added_products_keep_their_order(): void
    {
        $first = $this->product('პირველი', 1000);
        $second = $this->product('მეორე', 1000);

        $component = $this->screen();
        $component->call('add', $first->id)->call('add', $second->id);

        $orders = $this->promotion->products()->pluck('promotion_product.sort_order', 'products.id');

        $this->assertLessThan($orders[$second->id], $orders[$first->id]);
    }

    public function test_a_promotional_price_reaches_the_storefront(): void
    {
        $product = $this->product('ტელევიზორი', 1000);

        $this->screen()
            ->call('add', $product->id)
            ->set("price.{$product->id}", '800')
            ->call('saveRow', $product->id)
            ->assertHasNoErrors();

        $deal = Catalog::deals()[0];

        $this->assertSame(800.0, $deal['price']);
        $this->assertSame(1000.0, $deal['old']);
        $this->assertSame('−20%', $deal['discount']);
    }

    /** A percentage is the other way of saying it, and was never readable. */
    public function test_a_percentage_reaches_the_storefront(): void
    {
        $product = $this->product('სარეცხი', 1000);

        $this->screen()
            ->call('add', $product->id)
            ->set("percent.{$product->id}", '25')
            ->call('saveRow', $product->id)
            ->assertHasNoErrors();

        $this->assertSame(750.0, Catalog::deals()[0]['price']);
    }

    /**
     * The silent failure, now loud.
     *
     * Catalog::deals() drops a promotional price that is not below the shelf
     * price, because there is nothing to strike through — so a mistyped figure
     * produced a product with no discount and no explanation.
     */
    public function test_a_price_at_or_above_the_shelf_price_is_refused(): void
    {
        $product = $this->product('ლეპტოპი', 1000);

        $this->screen()
            ->call('add', $product->id)
            ->set("price.{$product->id}", '1200')
            ->call('saveRow', $product->id)
            ->assertHasErrors("price.{$product->id}");

        $this->assertNull($this->promotion->products()->first()->pivot->promo_price);
    }

    /** The pivot holds one or the other, as the column comment has always said. */
    public function test_a_price_and_a_percentage_together_are_refused(): void
    {
        $product = $this->product('მტვერსასრუტი', 1000);

        $this->screen()
            ->call('add', $product->id)
            ->set("price.{$product->id}", '800')
            ->set("percent.{$product->id}", '20')
            ->call('saveRow', $product->id)
            ->assertHasErrors("price.{$product->id}");
    }

    public function test_an_impossible_percentage_is_refused(): void
    {
        $product = $this->product('ფენი', 1000);

        $this->screen()
            ->call('add', $product->id)
            ->set("percent.{$product->id}", '150')
            ->call('saveRow', $product->id)
            ->assertHasErrors("percent.{$product->id}");
    }

    public function test_a_product_can_be_taken_back_out(): void
    {
        $product = $this->product('ჩაიდანი', 1000);

        $this->screen()->call('add', $product->id)->call('remove', $product->id);

        $this->assertSame(0, $this->promotion->products()->count());
        $this->assertNotNull($product->fresh(), 'removing from a campaign must not delete the product');
    }

    /* ------------------------------------------------------------------ search */

    /** Offering what is already in the campaign is how it gets added twice. */
    public function test_the_search_leaves_out_products_already_in_the_campaign(): void
    {
        $inside = $this->product('შიგნით', 1000);
        $outside = $this->product('გარეთ', 1000);

        $this->promotion->products()->attach($inside->id);

        /*
         * Asserted on the search results themselves, not on the page: a
         * product already in the campaign is listed in the table below, so
         * its name appears either way.
         */
        $component = $this->screen();

        $component->set('search', 'ა');
        $this->assertCount(0, $component->viewData('found'), 'one letter is not a search');

        $component->set('search', 'გარეთ');
        $this->assertTrue($component->viewData('found')->contains('id', $outside->id));

        $component->set('search', 'შიგნით');
        $this->assertFalse($component->viewData('found')->contains('id', $inside->id));
    }

    /* ------------------------------------------------------------------ campaign */

    public function test_a_campaign_can_be_created(): void
    {
        $this->screen()
            ->call('create')
            ->set('code', 'black-friday')
            ->set('title', 'შავი პარასკევი')
            ->call('savePromotion')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('promotions', ['code' => 'black-friday', 'type' => 'deal']);
    }

    /** The address is offered from the title while the campaign is new. */
    public function test_the_slug_is_suggested_from_the_title(): void
    {
        $component = $this->screen()
            ->call('create')
            ->set('title', 'შავი პარასკევი');

        $this->assertSame('shavi-paraskevi', $component->get('slug'));
    }

    /** Typed by hand, it is left alone. */
    public function test_a_hand_written_slug_is_not_overwritten(): void
    {
        $component = $this->screen()
            ->call('create')
            ->set('slug', 'bf-2026')
            ->set('title', 'შავი პარასკევი');

        $this->assertSame('bf-2026', $component->get('slug'));
    }

    /** Two campaigns cannot share an address in one language. */
    public function test_a_duplicate_slug_is_refused(): void
    {
        $this->promotion->saveTranslations(['ka' => ['title' => 'კვირის', 'slug' => 'kviris-aqcia']]);

        $this->screen()
            ->call('create')
            ->set('code', 'another')
            ->set('slug', 'kviris-aqcia')
            ->call('savePromotion')
            ->assertHasErrors('slug');
    }

    /** With nothing typed, the code becomes the address. */
    public function test_a_campaign_with_no_title_still_gets_an_address(): void
    {
        $this->screen()
            ->call('create')
            ->set('code', 'quiet-one')
            ->call('savePromotion')
            ->assertHasNoErrors();

        $this->assertSame('quiet-one', Promotion::where('code', 'quiet-one')->first()->urlKey());
    }

    /** The code goes in URLs and has to stay a code. */
    public function test_a_code_with_spaces_is_refused(): void
    {
        $this->screen()
            ->call('create')
            ->set('code', 'Black Friday!')
            ->call('savePromotion')
            ->assertHasErrors('code');
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

    protected function admin(): self
    {
        return $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    protected function screen()
    {
        $this->admin();

        return Livewire::test(Index::class)->set('promotionId', $this->promotion->id);
    }

    protected function product(string $name, float $price): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $this->category->id,
            'price' => $price,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => $name, 'slug' => str()->slug($name).'-'.$product->id]]);

        return $product->fresh();
    }
}
