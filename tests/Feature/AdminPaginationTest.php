<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paging through an admin list.
 *
 * Two things were missing at once and they compounded: Laravel ships Tailwind
 * pagination markup and nothing told it this admin is a Bootstrap theme, so
 * the controls rendered as two bare boxes on a dark page — and with no
 * lang/pagination file to read from, the words written on them were the
 * translation keys, "pagination.previous" and "pagination.next".
 */
class AdminPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => 'ტექნიკა', 'slug' => 'teqnika']]);

        foreach (range(1, 30) as $i) {
            $product = Product::create([
                'sku' => 'SKU-'.$i,
                'category_id' => $category->id,
                'price' => 100 + $i,
                'stock' => 5,
                'status' => Product::STATUS_ACTIVE,
                'published_at' => now()->subHour(),
            ]);

            $product->saveTranslations(['ka' => ['name' => 'პროდუქტი '.$i, 'slug' => 'p-'.$i]]);
        }

        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    /** The keys themselves were printed on the buttons. */
    public function test_the_buttons_are_not_translation_keys(): void
    {
        $this->get('/admin/products')
            ->assertOk()
            ->assertDontSee('pagination.previous')
            ->assertDontSee('pagination.next');
    }

    public function test_the_buttons_carry_the_translated_words(): void
    {
        $this->get('/admin/products')->assertOk()->assertSee('შემდეგი', false);
    }

    /** Bootstrap markup, because the admin is a Bootstrap theme. */
    public function test_the_controls_use_the_admin_theme(): void
    {
        $this->get('/admin/products')->assertOk()->assertSee('page-item', false);
    }

    /** And the second page is a different set of products. */
    public function test_the_second_page_shows_other_products(): void
    {
        $first = $this->get('/admin/products');
        $second = $this->get('/admin/products?page=2');

        $first->assertOk();
        $second->assertOk();

        $this->assertNotSame($first->getContent(), $second->getContent());
    }
}
