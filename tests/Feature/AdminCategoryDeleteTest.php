<?php

namespace Tests\Feature;

use App\Livewire\Admin\Categories\Index;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deleting a category, and where its contents go.
 *
 * Both halves asked Category::fallback() for somewhere to put the orphans and
 * no such method has ever existed, so opening the dialog on a root category
 * was a fatal error. The form has always offered "uncategorised" as its first
 * choice — a product is allowed to have no category, and the product list
 * filters on exactly that — so that is what a root now falls back to.
 */
class AdminCategoryDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected Category $phones;

    protected Category $watches;

    /** The case that crashed: a category with no parent. */
    public function test_the_dialog_opens_on_a_root_category(): void
    {
        $component = Livewire::test(Index::class)->call('confirmDelete', $this->phones->id);

        $component->assertOk();
        $this->assertNull($component->get('moveTo'), 'a root has no parent to fall back to');
    }

    /** A child offers its parent, which is where its contents belong. */
    public function test_a_child_defaults_to_its_parent(): void
    {
        $component = Livewire::test(Index::class)->call('confirmDelete', $this->watches->id);

        $this->assertSame($this->phones->id, $component->get('moveTo'));
    }

    /* ------------------------------------------------------------------ deleting */

    public function test_products_move_to_the_chosen_category(): void
    {
        $product = $this->product($this->watches);

        Livewire::test(Index::class)
            ->call('confirmDelete', $this->watches->id)
            ->set('moveTo', $this->phones->id)
            ->call('delete');

        $this->assertSame($this->phones->id, $product->fresh()->category_id);
        $this->assertNull(Category::find($this->watches->id));
    }

    /**
     * Nothing chosen leaves them without one.
     *
     * Which is a real state the shop handles: they drop out of the listings
     * and wait in the admin's "no category" filter.
     */
    public function test_products_can_be_left_uncategorised(): void
    {
        $product = $this->product($this->watches);

        Livewire::test(Index::class)
            ->call('confirmDelete', $this->watches->id)
            ->set('moveTo', null)
            ->call('delete');

        $this->assertNull($product->fresh()->category_id);
    }

    /** Children become roots rather than disappearing with their parent. */
    public function test_children_are_kept(): void
    {
        Livewire::test(Index::class)
            ->call('confirmDelete', $this->phones->id)
            ->set('moveTo', null)
            ->call('delete');

        $this->assertNotNull(Category::find($this->watches->id));
        $this->assertNull($this->watches->fresh()->parent_id);
    }

    /**
     * A supplier mapping pointing at it is moved too.
     *
     * Left behind, the next import would quietly refill a category nobody can
     * see any more.
     */
    public function test_the_supplier_mapping_follows(): void
    {
        $supplier = Supplier::create([
            'code' => 'zoommer', 'name' => 'Zoommer', 'driver' => 'X', 'is_active' => true,
            'priority' => 1, 'markup' => [['up_to' => 5000, 'percent' => 20]],
        ]);

        $id = DB::table('supplier_category_map')->insertGetId([
            'supplier_id' => $supplier->id,
            'external_name' => 'სმარტ საათები',
            'category_id' => $this->watches->id,
            'hits' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(Index::class)
            ->call('confirmDelete', $this->watches->id)
            ->set('moveTo', $this->phones->id)
            ->call('delete');

        $this->assertSame(
            $this->phones->id,
            (int) DB::table('supplier_category_map')->where('id', $id)->value('category_id'),
        );
    }

    /** A category cannot be moved inside itself. */
    public function test_moving_a_category_into_its_own_child_is_refused(): void
    {
        Livewire::test(Index::class)
            ->call('confirmDelete', $this->phones->id)
            ->set('moveTo', $this->watches->id)
            ->call('delete')
            ->assertHasErrors('moveTo');

        $this->assertNotNull(Category::find($this->phones->id));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->phones = $this->category('მობილურები', null);
        $this->watches = $this->category('სმარტ საათები', $this->phones->id);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    protected function category(string $name, ?int $parentId): Category
    {
        $category = Category::create(['parent_id' => $parentId, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => $name, 'slug' => str()->slug($name).'-'.$category->id]]);

        return $category->fresh();
    }

    protected function product(Category $category): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => 'პროდუქტი', 'slug' => 'produqti-'.$product->id]]);

        return $product->fresh();
    }
}
