<?php

namespace Tests\Feature;

use App\Livewire\Admin\Products\Index;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deleting a product from the admin did nothing.
 *
 * Not here — the component was always right. The buttons are the ones that ask
 * for a confirmation first, so they carry no wire:click and the dialog calls
 * the action itself. It called it as $wire.call(name), and Livewire 4's $wire
 * proxy has no "call" of its own: the name was read as a server method, the
 * component was asked to run one called "call", there is none, and the request
 * was refused in silence. Every confirmed action in the admin went that way.
 *
 * The browser half cannot be asserted from here, so these pin the half that
 * can: the actions themselves, with the selection arriving exactly as a
 * checkbox sends it — as strings.
 */
class ProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_is_soft_deleted(): void
    {
        $product = $this->product();

        $this->screen()->call('delete', $product->id);

        $this->assertNull(Product::find($product->id));
        $this->assertNotNull(Product::withTrashed()->find($product->id)->deleted_at);
    }

    public function test_a_batch_is_deleted(): void
    {
        $first = $this->product();
        $second = $this->product();
        $kept = $this->product();

        $this->screen()->set('selected', [$first->id, $second->id])->call('bulkDelete');

        $this->assertSame(1, Product::count());
        $this->assertSame($kept->id, Product::first()->id);
    }

    /**
     * A ticked checkbox arrives as a string.
     *
     * Which is what the browser sends, so the ids a bulk action is given are
     * never the integers a test would reach for by hand.
     */
    public function test_a_batch_of_string_ids_is_deleted(): void
    {
        $product = $this->product();

        $this->screen()->set('selected', [(string) $product->id])->call('bulkDelete');

        $this->assertSame(0, Product::count());
    }

    /** The selection is dropped afterwards, or the bar keeps offering the deleted. */
    public function test_the_selection_is_cleared(): void
    {
        $product = $this->product();

        $this->screen()
            ->set('selected', [$product->id])
            ->call('bulkDelete')
            ->assertSet('selected', [])
            ->assertSet('selectPage', false);
    }

    public function test_an_empty_selection_deletes_nothing(): void
    {
        $this->product();

        $this->screen()->set('selected', [])->call('bulkDelete');

        $this->assertSame(1, Product::count());
    }

    /**
     * A deleted product is gone from the shop, not merely from the admin.
     *
     * Soft deleted so an order that points at it still has something to name,
     * which is the whole reason it is not a hard delete.
     */
    public function test_a_deleted_product_leaves_the_catalogue(): void
    {
        $product = $this->product();

        $this->screen()->call('delete', $product->id);

        $this->assertFalse(Product::listable()->whereKey($product->id)->exists());
        $this->assertNotNull(Product::withTrashed()->find($product->id));
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
        return Livewire::actingAs($this->admin())->test(Index::class);
    }

    protected function admin(): User
    {
        return User::create([
            'name' => 'ოპერატორი',
            'email' => 'admin'.uniqid().'@iapi.ge',
            'password' => bcrypt('secret'),
            'is_admin' => true,
        ]);
    }

    protected function product(): Product
    {
        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => 'პროდუქტი', 'slug' => 'p-'.$product->id]]);

        return $product->fresh();
    }
}
