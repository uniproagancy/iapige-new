<?php

namespace Tests\Feature;

use App\Livewire\Admin\Callbacks\Index;
use App\Models\CallbackRequest;
use App\Models\Category;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Turning a phone call into an order.
 *
 * Meta sees a Lead when somebody leaves a number and a Purchase when the
 * order is paid, and treats them as one person only if the Purchase carries
 * the identifiers the browser had. The operator writes that order minutes
 * later from the admin — another request, another machine, no cookies — so
 * the sale was credited to nothing and the campaign only ever got the Lead.
 *
 * The identifiers are kept on the call for this moment and copied onto the
 * order's pixel snapshot here.
 */
class CallbackToOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    public function test_an_order_is_created_from_the_call(): void
    {
        $call = $this->phoneCall();

        Livewire::test(Index::class)->call('createOrder', $call->id);

        $order = Order::first();

        $this->assertNotNull($order);
        $this->assertSame('ნინო', $order->name);
        $this->assertSame('555123456', $order->phone);
        $this->assertSame($call->fresh()->order_id, $order->id);
    }

    /** The product they rang about is already on it. */
    public function test_the_product_becomes_a_line(): void
    {
        Livewire::test(Index::class)->call('createOrder', $this->phoneCall()->id);

        $order = Order::with('items')->first();

        $this->assertCount(1, $order->items);
        $this->assertSame($this->product->id, $order->items[0]->product_id);
        $this->assertSame(1000.0, (float) $order->total);
    }

    /** The whole point: the browser's identifiers travel with it. */
    public function test_the_pixel_data_is_carried_onto_the_order(): void
    {
        Livewire::test(Index::class)->call('createOrder', $this->phoneCall()->id);

        $snapshot = OrderPixelData::first();

        $this->assertSame('fb.1.123.456', $snapshot->fbp);
        $this->assertSame('fb.1.123.click', $snapshot->fbc);
        $this->assertSame('203.0.113.9', $snapshot->ip);
        $this->assertTrue((bool) $snapshot->consented);
        $this->assertNotEmpty($snapshot->event_id);
    }

    /**
     * A call that was never consented to stays that way.
     *
     * The snapshot carries the answer given at the time, and Pixel::send()
     * refuses to send anything the visitor did not agree to — writing the
     * order later must not quietly turn that into a yes.
     */
    public function test_a_call_without_consent_produces_an_unconsented_order(): void
    {
        $call = $this->phoneCall(['consented' => false]);

        Livewire::test(Index::class)->call('createOrder', $call->id);

        $this->assertFalse((bool) OrderPixelData::first()->consented);
    }

    /** The call is marked as dealt with, by whoever pressed the button. */
    public function test_the_call_is_marked_as_handled(): void
    {
        $call = $this->phoneCall();

        Livewire::test(Index::class)->call('createOrder', $call->id);

        $call->refresh();

        $this->assertSame(CallbackRequest::CALLED, $call->status);
        $this->assertNotNull($call->handled_at);
    }

    /** Pressing it twice must not write a second order. */
    public function test_a_second_press_opens_the_order_it_already_made(): void
    {
        $call = $this->phoneCall();

        Livewire::test(Index::class)->call('createOrder', $call->id);
        Livewire::test(Index::class)->call('createOrder', $call->id);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderPixelData::count());
    }

    /** A call about nothing in particular still becomes an order to fill in. */
    public function test_a_call_with_no_product_still_makes_an_order(): void
    {
        $call = $this->phoneCall(['product_id' => null]);

        Livewire::test(Index::class)->call('createOrder', $call->id);

        $order = Order::with('items')->first();

        $this->assertNotNull($order);
        $this->assertCount(0, $order->items);
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

        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    protected function phoneCall(array $overrides = []): CallbackRequest
    {
        return CallbackRequest::create(array_merge([
            'name' => 'ნინო',
            'phone' => '555123456',
            'product_id' => $this->product->id,
            'lead_event_id' => 'lead_123',
            'fbp' => 'fb.1.123.456',
            'fbc' => 'fb.1.123.click',
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0',
            'source_url' => 'https://dev.iapi.ge/ka/product/macivari',
            'consented' => true,
        ], $overrides));
    }
}
