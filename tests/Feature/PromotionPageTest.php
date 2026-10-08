<?php

namespace Tests\Feature;

use App\Livewire\Pages\Promotion as PromotionPage;
use App\Livewire\Pages\Promotions as PromotionsPage;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\Catalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A page per campaign.
 *
 * The front page carries one rail, which is enough for one campaign and
 * useless for several — the rest had no address at all. Each now has its
 * own, and an index lists the ones running.
 */
class PromotionPageTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    /* ------------------------------------------------------------------ one campaign */

    public function test_a_campaign_lists_its_own_products(): void
    {
        $campaign = $this->campaign('week-deal', 'კვირის აქცია');
        $campaign->products()->attach($this->product('მაცივარი', 1000)->id, ['promo_price' => 800]);

        $other = $this->campaign('black-friday', 'შავი პარასკევი');
        $other->products()->attach($this->product('ტელევიზორი', 2000)->id, ['promo_price' => 1500]);

        Livewire::test(PromotionPage::class, ['code' => 'week-deal'])
            ->assertSee('კვირის აქცია')
            ->assertSee('მაცივარი')
            ->assertDontSee('ტელევიზორი');
    }

    /** The price here is the campaign price, as everywhere else. */
    public function test_the_campaign_price_is_what_the_page_shows(): void
    {
        $campaign = $this->campaign('week-deal', 'კვირის აქცია');
        $campaign->products()->attach($this->product('მაცივარი', 1000)->id, ['promo_price' => 800]);

        $products = Catalog::campaignPage(Catalog::campaign('week-deal'))['products'];

        $this->assertSame(800.0, $products[0]['price']);
        $this->assertSame(1000.0, $products[0]['old']);
    }

    /** A draft product is in the campaign but not on the page. */
    public function test_only_published_products_are_listed(): void
    {
        $campaign = $this->campaign('week-deal', 'კვირის აქცია');

        $live = $this->product('გამოქვეყნებული', 1000);
        $draft = $this->product('დრაფტი', 1000);
        $draft->update(['status' => Product::STATUS_DRAFT]);

        $campaign->products()->attach([$live->id, $draft->id]);

        $products = Catalog::campaignPage(Catalog::campaign('week-deal'))['products'];

        $this->assertCount(1, $products);
        $this->assertSame($live->id, $products[0]['id']);
    }

    /** The order set in the admin is the order on the page. */
    public function test_the_admin_order_is_respected(): void
    {
        $campaign = $this->campaign('week-deal', 'კვირის აქცია');

        $first = $this->product('პირველი', 1000);
        $second = $this->product('მეორე', 1000);

        $campaign->products()->attach($second->id, ['sort_order' => 1]);
        $campaign->products()->attach($first->id, ['sort_order' => 2]);

        $products = Catalog::campaignPage(Catalog::campaign('week-deal'))['products'];

        $this->assertSame([$second->id, $first->id], array_column($products, 'id'));
    }

    /* ------------------------------------------------------------------ reachability */

    /** A campaign that has ended must not keep advertising its prices. */
    public function test_a_finished_campaign_is_gone(): void
    {
        $this->campaign('last-week', 'გასული', ends: now()->subDay());

        $this->expectException(ModelNotFoundException::class);

        Catalog::campaign('last-week');
    }

    public function test_a_campaign_that_has_not_started_is_not_reachable(): void
    {
        $this->campaign('soon', 'მალე', starts: now()->addWeek());

        $this->expectException(ModelNotFoundException::class);

        Catalog::campaign('soon');
    }

    public function test_a_switched_off_campaign_is_not_reachable(): void
    {
        $this->campaign('paused', 'შეჩერებული')->update(['is_active' => false]);

        $this->expectException(ModelNotFoundException::class);

        Catalog::campaign('paused');
    }

    /* ------------------------------------------------------------------ the index */

    public function test_the_index_lists_every_running_campaign(): void
    {
        $first = $this->campaign('week-deal', 'კვირის აქცია');
        $first->products()->attach($this->product('ა', 1000)->id);

        $second = $this->campaign('black-friday', 'შავი პარასკევი');
        $second->products()->attach($this->product('ბ', 1000)->id);

        Livewire::test(PromotionsPage::class)
            ->assertSee('კვირის აქცია')
            ->assertSee('შავი პარასკევი');
    }

    /**
     * An empty campaign is not worth a place in the list.
     *
     * It is still reachable by its own address, which is what somebody
     * setting it up needs, but it does not advertise itself as an offer with
     * nothing in it.
     */
    public function test_an_empty_campaign_is_left_out_of_the_index(): void
    {
        $this->campaign('empty-one', 'ცარიელი');

        $this->assertSame([], Catalog::campaigns());
    }

    public function test_a_finished_campaign_is_left_out_of_the_index(): void
    {
        $campaign = $this->campaign('last-week', 'გასული', ends: now()->subDay());
        $campaign->products()->attach($this->product('ა', 1000)->id);

        $this->assertSame([], Catalog::campaigns());
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

        Catalog::flushTree();
    }

    protected function campaign(string $code, string $title, $starts = null, $ends = null): Promotion
    {
        $campaign = Promotion::create([
            'code' => $code, 'type' => 'deal', 'is_active' => true, 'sort_order' => 1,
            'starts_at' => $starts ?? now()->subDay(),
            'ends_at' => $ends ?? now()->addDay(),
        ]);

        $campaign->saveTranslations(['ka' => ['title' => $title]]);

        Catalog::flushTree();

        return $campaign->fresh();
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
