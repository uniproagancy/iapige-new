<?php

namespace Tests\Feature;

use App\Livewire\Pages\Home;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The deals banner on the front page.
 *
 * It rendered unconditionally, so a shop with no campaign running still
 * promised "this week's offer" above an empty strip — which was every shop,
 * because until the admin screen existed nothing could put a product into a
 * campaign. The heading was a fixed phrase too, so a campaign named in the
 * admin announced itself as something else entirely.
 */
class HomePromoTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    public function test_no_campaign_means_no_banner(): void
    {
        $this->home()->assertDontSee(__('home.deals_title'));
    }

    /** A campaign with nothing in it is still nothing to announce. */
    public function test_a_campaign_without_products_shows_no_banner(): void
    {
        $this->campaign();

        $this->home()->assertDontSee(__('home.deals_title'));
    }

    public function test_a_campaign_with_products_shows_the_banner(): void
    {
        $promotion = $this->campaign();
        $product = $this->product('სააქციო მაცივარი', 1000);

        $promotion->products()->attach($product->id, ['promo_price' => 800]);

        $this->home()
            ->assertSee(__('home.deals_title'))
            ->assertSee('სააქციო მაცივარი');
    }

    /** The campaign's own name, when it has been given one. */
    public function test_the_campaign_names_the_banner(): void
    {
        $promotion = $this->campaign();
        $promotion->saveTranslations(['ka' => ['title' => 'შავი პარასკევი', 'subtitle' => 'სამ დღეს მხოლოდ']]);

        $promotion->products()->attach($this->product('ტელევიზორი', 2000)->id, ['discount_percent' => 30]);

        Catalog::flushTree();

        $this->home()
            ->assertSee('შავი პარასკევი')
            ->assertSee('სამ დღეს მხოლოდ')
            ->assertDontSee(__('home.deals_title'));
    }

    /** An expired campaign is not a running one. */
    public function test_a_finished_campaign_shows_nothing(): void
    {
        $promotion = Promotion::create([
            'code' => 'last-week', 'type' => 'deal', 'is_active' => true, 'sort_order' => 1,
            'starts_at' => now()->subWeeks(2), 'ends_at' => now()->subWeek(),
        ]);

        $promotion->products()->attach($this->product('ძველი აქცია', 500)->id, ['promo_price' => 400]);

        Catalog::flushTree();

        $this->home()->assertDontSee('ძველი აქცია');
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

    /**
     * Rendered as the component, not over HTTP.
     *
     * The supported locales are read out of the languages table when the
     * application boots, which in a test is before any row exists — so every
     * localised URL answers 404 however correct the page is.
     */
    protected function home()
    {
        return Livewire::test(Home::class);
    }

    protected function campaign(): Promotion
    {
        $promotion = Promotion::create([
            'code' => 'week-deal', 'type' => 'deal', 'is_active' => true, 'sort_order' => 1,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);

        Catalog::flushTree();

        return $promotion;
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
