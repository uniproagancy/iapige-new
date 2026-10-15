<?php

namespace Tests\Feature;

use App\Livewire\Admin\Brands\Index;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The brands screen died the moment anybody opened it.
 *
 * "Undefined variable $onlyEmpty": the button for the filter was in the view
 * and the property backing it never was — and the filter it was meant to
 * apply had not been written either. The page could not render at all, so the
 * whole screen was unreachable over one unwritten line.
 *
 * A screen that throws on render is the sort of thing a single test catches,
 * which is why there is one now for opening it at all.
 */
class AdminBrandsScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_screen_opens(): void
    {
        $this->brand('Acme');

        $this->screen()->assertOk()->assertSee('Acme');
    }

    /* ------------------------------------------------------------------ the filter */

    /** A brand nobody sells is noise in the sidebar; the button finds them. */
    public function test_it_can_show_only_the_brands_without_products(): void
    {
        $this->brand('Sells things', withProduct: true);
        $this->brand('Sells nothing');

        $this->screen()
            ->set('onlyEmpty', true)
            ->assertSee('Sells nothing')
            ->assertDontSee('Sells things');
    }

    public function test_every_brand_shows_by_default(): void
    {
        $this->brand('Sells things', withProduct: true);
        $this->brand('Sells nothing');

        $this->screen()
            ->assertSee('Sells things')
            ->assertSee('Sells nothing');
    }

    /** The button is a toggle, so it has to come back off. */
    public function test_the_filter_can_be_turned_off_again(): void
    {
        $this->brand('Sells things', withProduct: true);

        $this->screen()
            ->set('onlyEmpty', true)
            ->assertDontSee('Sells things')
            ->set('onlyEmpty', false)
            ->assertSee('Sells things');
    }

    /** Narrowing the list from page three would otherwise show nothing. */
    public function test_toggling_it_returns_to_the_first_page(): void
    {
        $this->brand('Sells nothing');

        $this->screen()
            ->call('gotoPage', 3)
            ->set('onlyEmpty', true)
            ->assertSet('paginators.page', 1);
    }

    /** The search still narrows things while the filter is on. */
    public function test_the_search_and_the_filter_work_together(): void
    {
        $this->brand('Empty one');
        $this->brand('Empty two');

        $this->screen()
            ->set('onlyEmpty', true)
            ->set('search', 'two')
            ->assertSee('Empty two')
            ->assertDontSee('Empty one');
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

    protected function screen()
    {
        $admin = User::create([
            'name' => 'admin', 'email' => 'a'.uniqid().'@iapi.ge',
            'password' => bcrypt('x'), 'is_admin' => true,
        ]);

        return Livewire::actingAs($admin)->test(Index::class);
    }

    protected function brand(string $name, bool $withProduct = false): Brand
    {
        $brand = Brand::create([
            'name' => $name,
            'slug' => str()->slug($name),
            'is_active' => true,
        ]);

        if ($withProduct) {
            $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

            Product::create([
                'sku' => 'SKU-'.str()->random(6),
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'price' => 100,
                'stock' => 5,
                'status' => Product::STATUS_ACTIVE,
            ]);
        }

        return $brand;
    }
}
