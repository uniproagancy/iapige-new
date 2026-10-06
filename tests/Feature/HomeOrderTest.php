<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home page shows the newest products first.
 *
 * It ordered by sales_count, which is zero on every product until something
 * sells — and a column of equal values leaves the order to the database, which
 * hands back insertion order. So the front page of a shop still filling its
 * catalogue led with whatever had been imported first.
 */
class HomeOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

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

        Catalog::flushTree();
    }

    public function test_a_section_leads_with_the_newest_product(): void
    {
        $first = $this->product('ყველაზე ძველი');
        $middle = $this->product('შუა');
        $newest = $this->product('ყველაზე ახალი');

        $products = Catalog::sections()[0]['products'];

        $this->assertSame(
            [$newest->id, $middle->id, $first->id],
            array_column($products, 'id'),
        );
    }

    /**
     * Not even a best-seller moves ahead of a newer product.
     *
     * This is the behaviour that was asked for: order by id. If best-sellers
     * should lead once the shop has sales, sales_count goes back in front of
     * id and this expectation flips.
     */
    public function test_sales_do_not_reorder_the_section(): void
    {
        $old = $this->product('ძველი ბესტსელერი');
        $new = $this->product('ახალი');

        $old->update(['sales_count' => 500]);

        $products = Catalog::sections()[0]['products'];

        $this->assertSame([$new->id, $old->id], array_column($products, 'id'));
    }

    /** A draft is not on the front page whatever its id. */
    public function test_only_published_products_appear(): void
    {
        $live = $this->product('გამოქვეყნებული');
        $draft = $this->product('დრაფტი');
        $draft->update(['status' => Product::STATUS_DRAFT]);

        $products = Catalog::sections()[0]['products'];

        $this->assertSame([$live->id], array_column($products, 'id'));
    }

    /** The deals rail had no order at all, so the database chose. */
    public function test_the_deals_rail_leads_with_the_newest(): void
    {
        $old = $this->product('ძველი აქცია');
        $new = $this->product('ახალი აქცია');

        $promotion = Promotion::create([
            'code' => 'home-deal', 'type' => 'deal', 'is_active' => true, 'sort_order' => 1,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        $promotion->products()->attach([$old->id, $new->id]);

        $deals = Catalog::deals();

        $this->assertSame([$new->id, $old->id], array_column($deals, 'id'));
    }

    protected function product(string $name): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $this->category->id,
            'price' => 100,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => $name, 'slug' => str()->slug($name).'-'.$product->id]]);

        return $product->fresh();
    }
}
