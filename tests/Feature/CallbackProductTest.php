<?php

namespace Tests\Feature;

use App\Livewire\Admin\Callbacks\Index;
use App\Livewire\Forms\CallbackForm;
use App\Models\CallbackRequest;
use App\Models\Category;
use App\Models\Language;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Which product the call was about.
 *
 * The form takes a product id and the buy card, the only place that renders
 * it on a product page, was not passing one — so every request arrived
 * knowing nothing about what the caller was looking at. The operator had a
 * name and a number, and the order written from the call had no line on it.
 */
class CallbackProductTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    public function test_the_call_records_the_product_it_came_from(): void
    {
        Livewire::test(CallbackForm::class, ['productId' => $this->product->id])
            ->set('name', 'ნინო')
            ->set('phone', '555123456')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame($this->product->id, CallbackRequest::first()->product_id);
    }

    /**
     * The page itself hands the product over.
     *
     * The test above drives the form directly, which the form always handled
     * correctly — the fault was in the markup around it, where the buy card
     * rendered the form and passed nothing. Read off the page the shopper
     * actually gets.
     */
    public function test_the_product_page_hands_the_form_its_product(): void
    {
        $html = Livewire::test(\App\Livewire\Pages\Product::class, ['slug' => 'macivari'])->html();

        /*
         * Narrowed to this one component's snapshot. The wishlist heart on the
         * same page also has a productId, so looking for that name anywhere in
         * the page finds it whether the callback form was given one or not.
         */
        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        $form = collect($matches[1])->first(fn (string $snapshot) => str_contains($snapshot, 'forms.callback-form'));

        $this->assertNotNull($form, 'the product page should render the callback form');

        $this->assertStringContainsString(
            '&quot;productId&quot;:'.$this->product->id,
            $form,
            'the callback form on the product page must know which product it is on',
        );
    }

    /** The general form carries none, and that is not an error. */
    public function test_a_call_from_no_particular_product_still_works(): void
    {
        Livewire::test(CallbackForm::class)
            ->set('name', 'გიორგი')
            ->set('phone', '555654321')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertNull(CallbackRequest::first()->product_id);
    }

    /** And the order written from the call is about that product. */
    public function test_the_order_is_built_around_that_product(): void
    {
        Livewire::test(CallbackForm::class, ['productId' => $this->product->id])
            ->set('name', 'ნინო')
            ->set('phone', '555123456')
            ->call('send');

        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(Index::class)
            ->call('createOrder', CallbackRequest::first()->id);

        $order = Order::with('items')->first();

        $this->assertCount(1, $order->items);
        $this->assertSame($this->product->id, $order->items[0]->product_id);
        $this->assertSame('მაცივარი', $order->items[0]->name);
        $this->assertSame(1000.0, (float) $order->total);
    }

    /** The price is read when the order is written, not when the call came in. */
    public function test_the_order_takes_the_price_of_the_day(): void
    {
        Livewire::test(CallbackForm::class, ['productId' => $this->product->id])
            ->set('name', 'ნინო')
            ->set('phone', '555123456')
            ->call('send');

        $this->product->update(['price' => 1200]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(Index::class)
            ->call('createOrder', CallbackRequest::first()->id);

        $this->assertSame(1200.0, (float) Order::first()->total);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => 'ტექნიკა', 'slug' => 'teqnika']]);

        $this->product = Product::create([
            'sku' => 'SKU-1', 'category_id' => $category->id, 'price' => 1000, 'stock' => 5,
            'status' => Product::STATUS_ACTIVE, 'published_at' => now()->subHour(),
        ]);
        $this->product->saveTranslations(['ka' => ['name' => 'მაცივარი', 'slug' => 'macivari']]);
    }
}
