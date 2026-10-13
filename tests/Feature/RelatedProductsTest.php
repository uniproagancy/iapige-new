<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Similar products" was showing anything from the same corner of the shop.
 *
 * The row was filled from the whole of the parent category's subtree, so a
 * washing machine was shown beside an oven and an extractor hood — related
 * only in the sense that both are appliances. Somebody comparing washing
 * machines wants the other washing machines.
 *
 * Narrowing it has a cost, though: a thin category cannot fill five cards, and
 * two products under a half-empty heading reads as a shop with nothing in it.
 * So both halves are pinned here — its own category first, siblings only to
 * fill what is left.
 */
class RelatedProductsTest extends TestCase
{
    use RefreshDatabase;

    protected Category $parent;

    protected Category $washers;

    protected Category $ovens;

    /* ------------------------------------------------------------------ the category */

    public function test_the_row_is_this_products_own_category(): void
    {
        $product = $this->product('სარეცხი მანქანა A', $this->washers);

        // enough of its own to fill the row, so nothing is borrowed
        foreach (range('B', 'F') as $letter) {
            $this->product('სარეცხი მანქანა '.$letter, $this->washers);
        }

        $this->product('ღუმელი', $this->ovens);
        $this->product('გამწოვი', $this->ovens);

        $names = $this->namesOf($product);

        $this->assertContains('სარეცხი მანქანა B', $names);
        $this->assertNotContains('ღუმელი', $names);
        $this->assertNotContains('გამწოვი', $names);
    }

    public function test_the_product_itself_is_not_among_them(): void
    {
        $product = $this->product('სარეცხი მანქანა A', $this->washers);

        $this->product('სარეცხი მანქანა B', $this->washers);

        $this->assertNotContains('სარეცხი მანქანა A', $this->namesOf($product));
    }

    /** The best sellers first, because that is what a row of five should be. */
    public function test_the_best_sellers_come_first(): void
    {
        $product = $this->product('სარეცხი მანქანა A', $this->washers);

        $this->product('იშვიათი', $this->washers)->update(['sales_count' => 1]);
        $this->product('პოპულარული', $this->washers)->update(['sales_count' => 99]);

        $this->assertSame('პოპულარული', $this->namesOf($product)[0]);
    }

    /* ------------------------------------------------------------------ filling the row */

    /**
     * A thin category is topped up from its siblings.
     *
     * Not from the whole shop — only from what sits under the same parent,
     * which is the old rule kept for the one case it was right for.
     */
    public function test_a_thin_category_is_topped_up_from_its_siblings(): void
    {
        $product = $this->product('სარეცხი მანქანა A', $this->washers);

        $this->product('სარეცხი მანქანა B', $this->washers);

        for ($i = 1; $i <= 5; $i++) {
            $this->product('ღუმელი '.$i, $this->ovens);
        }

        $related = Catalog::related($product);

        $this->assertCount(5, $related);

        // its own category first, whatever the siblings' sales
        $this->assertSame('სარეცხი მანქანა B', $related[0]['name']);
    }

    /** A category that can fill the row borrows nothing. */
    public function test_a_full_category_borrows_nothing(): void
    {
        $product = $this->product('სარეცხი მანქანა A', $this->washers);

        for ($i = 1; $i <= 6; $i++) {
            $this->product('სარეცხი მანქანა '.$i, $this->washers);
        }

        $this->product('ღუმელი', $this->ovens);

        $this->assertNotContains('ღუმელი', $this->namesOf($product));
    }

    public function test_nothing_is_repeated(): void
    {
        $product = $this->product('სარეცხი მანქანა A', $this->washers);

        $this->product('სარეცხი მანქანა B', $this->washers);
        $this->product('ღუმელი', $this->ovens);

        $ids = array_column(Catalog::related($product), 'id');

        $this->assertSame($ids, array_unique($ids));
    }

    /** A product nobody has categorised has nothing to be similar to. */
    public function test_an_uncategorised_product_has_no_row(): void
    {
        $product = $this->product('უკატეგორიო', $this->washers);
        $product->update(['category_id' => null]);

        $this->assertSame([], Catalog::related($product->fresh()));
    }

    /* ------------------------------------------------------------------ the button */

    /**
     * "All" leads to the category the row came from.
     *
     * It pointed at the whole catalogue, which threw away the one thing the
     * visitor had told us — that they are looking at washing machines.
     */
    public function test_the_all_button_leads_to_the_category(): void
    {
        $product = $this->product('სარეცხი მანქანა A', $this->washers);

        $page = Catalog::productPage($product->fresh());

        $this->assertSame(route('catalog', $this->washers->slug), $page['relatedUrl']);
    }

    public function test_an_uncategorised_product_falls_back_to_the_catalogue(): void
    {
        $product = $this->product('უკატეგორიო', $this->washers);
        $product->update(['category_id' => null]);

        $page = Catalog::productPage($product->fresh());

        $this->assertSame(route('catalog'), $page['relatedUrl']);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->parent = $this->category('ტექნიკა', 'teqnika');
        $this->washers = $this->category('სარეცხი მანქანები', 'sarecxi', $this->parent);
        $this->ovens = $this->category('ღუმელები', 'gumeli', $this->parent);
    }

    /** @return array<int, string> */
    protected function namesOf(Product $product): array
    {
        return array_column(Catalog::related($product->fresh()), 'name');
    }

    protected function category(string $name, string $slug, ?Category $parent = null): Category
    {
        $category = Category::create([
            'parent_id' => $parent?->id,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $category->saveTranslations(['ka' => ['name' => $name, 'slug' => $slug]]);

        return $category->fresh();
    }

    protected function product(string $name, Category $category): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.str()->random(8),
            'category_id' => $category->id,
            'price' => 1000,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => [
            'name' => $name,
            'slug' => str()->slug($name).'-'.$product->id,
        ]]);

        return $product->fresh();
    }
}
