<?php

namespace Tests\Feature;

use App\Livewire\Admin\Products\Index;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Moving a selection to a brand did nothing.
 *
 * The button is disabled until the server has a brand to apply, and the
 * picker was a deferred model — which sends nothing until the next request.
 * So choosing a brand left the server believing none had been chosen, the
 * button stayed disabled, and nothing on that row would have triggered the
 * round trip that would have told it otherwise.
 *
 * The category picker beside it worked only because it had no such guard.
 * Both now report the choice at once, which is the only arrangement where
 * the guard means anything.
 */
class BulkBrandTest extends TestCase
{
    use RefreshDatabase;

    protected Brand $brand;

    protected Category $category;

    /* ------------------------------------------------------------------ the brand */

    public function test_a_selection_is_moved_to_a_brand(): void
    {
        $first = $this->product();
        $second = $this->product();

        $this->screen()
            ->set('selected', [$first->id, $second->id])
            ->set('bulkBrand', $this->brand->id)
            ->call('bulkSetBrand');

        $this->assertSame($this->brand->id, $first->fresh()->brand_id);
        $this->assertSame($this->brand->id, $second->fresh()->brand_id);
    }

    /** A ticked checkbox arrives as a string, which is what the browser sends. */
    public function test_string_ids_are_moved_too(): void
    {
        $product = $this->product();

        $this->screen()
            ->set('selected', [(string) $product->id])
            ->set('bulkBrand', (string) $this->brand->id)
            ->call('bulkSetBrand');

        $this->assertSame($this->brand->id, $product->fresh()->brand_id);
    }

    public function test_choosing_no_brand_changes_nothing(): void
    {
        $product = $this->product();

        $this->screen()
            ->set('selected', [$product->id])
            ->set('bulkBrand', null)
            ->call('bulkSetBrand');

        $this->assertNull($product->fresh()->brand_id);
    }

    /**
     * A locked product keeps the brand somebody chose for it.
     *
     * Locking is what makes a hand edit survive the next import, so a bulk
     * move must respect it too.
     */
    public function test_a_locked_product_is_left_alone(): void
    {
        $product = $this->product();
        $product->update(['taxonomy_lock' => true]);

        $this->screen()
            ->set('selected', [$product->id])
            ->set('bulkBrand', $this->brand->id)
            ->call('bulkSetBrand');

        $this->assertNull($product->fresh()->brand_id);
    }

    /** The selection is dropped afterwards, or the bar keeps offering it. */
    public function test_the_selection_is_cleared(): void
    {
        $product = $this->product();

        $this->screen()
            ->set('selected', [$product->id])
            ->set('bulkBrand', $this->brand->id)
            ->call('bulkSetBrand')
            ->assertSet('selected', [])
            ->assertSet('bulkBrand', null);
    }

    /* ------------------------------------------------------------------ the markup */

    /**
     * Both pickers report their choice at once.
     *
     * A deferred model with a disabled guard on the button is the bug this
     * file is named for, and it is invisible from the component: only the
     * template says which kind of binding it is.
     */
    public function test_both_pickers_are_live(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/products/index.blade.php'));

        $this->assertStringContainsString('wire:model.live="bulkBrand"', $view);
        $this->assertStringContainsString('wire:model.live="bulkCategory"', $view);
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

        $this->brand = Brand::create(['name' => 'Comfo', 'slug' => 'comfo', 'is_active' => true]);
    }

    protected function screen()
    {
        $admin = User::create([
            'name' => 'admin', 'email' => 'a'.uniqid().'@iapi.ge',
            'password' => bcrypt('x'), 'is_admin' => true,
        ]);

        return Livewire::actingAs($admin)->test(Index::class);
    }

    protected function product(): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $this->category->id,
            'price' => 100,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => 'პროდუქტი', 'slug' => 'p-'.$product->id]]);

        return $product->fresh();
    }
}
