<?php

namespace Tests\Feature;

use App\Livewire\Admin\Orders\Create;
use App\Models\CallbackRequest;
use App\Models\Category;
use App\Models\DeliveryCity;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An operator takes an order over the phone.
 *
 * Most of what this shop sells is agreed in a call, and the only way to record
 * one was the single-product shortcut on a callback request — one item, the
 * default delivery, the default payment, and the operator editing the order
 * afterwards to fix all three.
 *
 * What matters here is that the order it writes is indistinguishable from one a
 * customer placed: the same prices read from the database, the same stock moved,
 * the same refusals. An operator's screen that quietly charged a stale price
 * would be worse than no screen.
 */
class OperatorOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected DeliveryCity $city;

    /* ------------------------------------------------------------------ the steps */

    public function test_it_opens_on_the_customer(): void
    {
        $this->screen()->assertSet('step', 'customer');
    }

    /** A step with nothing in it does not let the operator past. */
    public function test_a_nameless_customer_cannot_be_passed(): void
    {
        $this->screen()
            ->call('next')
            ->assertHasErrors(['name', 'phone'])
            ->assertSet('step', 'customer');
    }

    public function test_a_named_customer_moves_on(): void
    {
        $this->screen()
            ->set('name', 'გიორგი')
            ->set('phone', '995555123456')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 'items');
    }

    public function test_an_empty_order_cannot_be_passed(): void
    {
        $this->atStep('items')
            ->call('next')
            ->assertHasErrors('qty')
            ->assertSet('step', 'items');
    }

    public function test_a_courier_order_needs_a_city_and_an_address(): void
    {
        $this->atStep('delivery')
            ->set('cityId', null)
            ->set('address', '')
            ->call('next')
            ->assertHasErrors(['cityId', 'address'])
            ->assertSet('step', 'delivery');
    }

    /** Collected in person: there is no address to ask for. */
    public function test_a_collected_order_needs_neither(): void
    {
        $this->atStep('delivery')
            ->set('delivery', 'pickup')
            ->set('cityId', null)
            ->set('address', '')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 'payment');
    }

    /** Backwards is free; forwards is not. */
    public function test_a_step_already_passed_can_be_reopened(): void
    {
        $this->atStep('delivery')->call('goTo', 'customer')->assertSet('step', 'customer');
    }

    public function test_a_step_not_yet_reached_cannot_be_jumped_to(): void
    {
        $this->screen()->call('goTo', 'payment')->assertSet('step', 'customer');
    }

    /* ------------------------------------------------------------------ the items */

    public function test_a_product_is_added_and_counted(): void
    {
        $product = $this->product(price: 500);

        $screen = $this->screen()->call('add', $product->id);

        $screen->assertSet('qty', [$product->id => 1]);
        $this->assertSame(500.0, $screen->instance()->subtotal());
    }

    public function test_adding_the_same_product_twice_raises_the_quantity(): void
    {
        $product = $this->product(price: 500);

        $screen = $this->screen()->call('add', $product->id)->call('add', $product->id);

        $screen->assertSet('qty', [$product->id => 2]);
        $this->assertSame(1000.0, $screen->instance()->subtotal());
    }

    /**
     * A product the shop cannot sell is refused when it is added.
     *
     * Not at the end: an operator who has taken the whole order and is then
     * told the first item was never available has to ring back.
     */
    public function test_a_suspended_product_cannot_be_added(): void
    {
        $product = $this->product(price: 500);
        $product->update(['status' => Product::STATUS_DRAFT]);

        $this->screen()->call('add', $product->id)->assertSet('qty', []);
    }

    public function test_a_preorder_cannot_be_added(): void
    {
        $product = $this->product(price: 500);
        $product->update(['is_preorder' => true]);

        $this->screen()->call('add', $product->id)->assertSet('qty', []);
    }

    public function test_the_last_one_removed_empties_the_line(): void
    {
        $product = $this->product(price: 500);

        $this->screen()->call('add', $product->id)->call('decrement', $product->id)
            ->assertSet('qty', []);
    }

    /* ------------------------------------------------------------------ the money */

    /**
     * The price is the database's, not the screen's.
     *
     * A phone call is long enough for a campaign to start or end, and the
     * operator's screen has been open the whole time.
     */
    public function test_a_price_changed_during_the_call_is_the_one_charged(): void
    {
        $product = $this->product(price: 500);

        $screen = $this->ready($product);

        $product->update(['price' => 450]);

        $screen->call('place');

        $this->assertSame('450.00', Order::first()->items->first()->price);
        $this->assertSame('450.00', Order::first()->subtotal);
    }

    public function test_the_courier_fee_is_added(): void
    {
        $product = $this->product(price: 500);

        $this->ready($product)->call('place');

        $order = Order::first();

        $this->assertSame('5.00', $order->shipping);
        $this->assertSame('505.00', $order->total);
    }

    /** The operator's own figure wins: it is what the customer was told. */
    public function test_a_hand_typed_courier_fee_is_used(): void
    {
        $product = $this->product(price: 500);

        $this->ready($product)->set('shippingOverride', '12.5')->call('place');

        $this->assertSame('12.50', Order::first()->shipping);
        $this->assertSame('512.50', Order::first()->total);
    }

    public function test_collecting_in_person_costs_nothing(): void
    {
        $product = $this->product(price: 500);

        $this->ready($product)->set('delivery', 'pickup')->call('place');

        $order = Order::first();

        $this->assertSame('0.00', $order->shipping);
        $this->assertSame('pickup', $order->delivery);
        $this->assertNull($order->address);
    }

    /* ------------------------------------------------------------------ placing it */

    public function test_the_order_is_written_with_everything_chosen(): void
    {
        $product = $this->product(price: 500);

        $this->ready($product)->call('place');

        $order = Order::first();

        $this->assertNotNull($order);
        $this->assertSame('გიორგი', $order->name);
        $this->assertSame('995555123456', $order->phone);
        $this->assertSame('courier', $order->delivery);
        $this->assertSame($this->city->id, $order->delivery_city_id);
        $this->assertSame('თბილისი', $order->city);
        $this->assertSame('ვაჟა-ფშაველას 76', $order->address);
        $this->assertSame('cash', $order->payment);
        $this->assertSame('new', $order->status);
    }

    /** Nothing is taken at the desk, so the order is saved unpaid. */
    public function test_the_order_is_saved_unpaid(): void
    {
        $this->ready($this->product(price: 500))->call('place');

        $order = Order::first();

        $this->assertFalse($order->is_paid);
        $this->assertSame('pending', $order->payment_status);
    }

    public function test_the_items_are_written(): void
    {
        $product = $this->product(price: 500);

        $this->ready($product)->set('qty', [$product->id => 3])->call('place');

        $item = Order::first()->items->first();

        $this->assertSame($product->id, $item->product_id);
        $this->assertSame(3, $item->qty);
        $this->assertSame('1500.00', $item->sum);
        $this->assertSame($product->sku, $item->sku);
    }

    public function test_the_stock_moves(): void
    {
        $product = $this->product(price: 500, stock: 10);

        $this->ready($product)->set('qty', [$product->id => 3])->call('place');

        $this->assertSame(7, (int) $product->fresh()->stock);
        $this->assertSame(3, (int) $product->fresh()->sales_count);
    }

    /**
     * Who took the order, on the order.
     *
     * When a price or an address turns out to be wrong, the first question is
     * whether the customer typed it or an operator did.
     */
    public function test_the_operator_is_recorded(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Create::class)
            ->set('name', 'გიორგი')
            ->set('phone', '995555123456')
            ->set('qty', [$this->product(price: 500)->id => 1])
            ->set('cityId', $this->city->id)
            ->set('address', 'ვაჟა-ფშაველას 76')
            ->set('payment', 'cash')
            ->call('place');

        $event = Order::first()->events->first();

        $this->assertSame($admin->id, $event->user_id);
        $this->assertStringContainsString($admin->name, $event->note);
    }

    public function test_an_order_cannot_be_placed_with_nothing_in_it(): void
    {
        $this->ready($this->product(price: 500))
            ->set('qty', [])
            ->call('place')
            ->assertHasErrors('qty')
            ->assertSet('step', 'items');

        $this->assertSame(0, Order::count());
    }

    /**
     * A method switched off while the screen was open is refused.
     *
     * `exists` cannot see is_active, so the rule has to be checked again at
     * the moment the order is written.
     */
    public function test_a_method_switched_off_mid_call_is_refused(): void
    {
        $screen = $this->ready($this->product(price: 500));

        PaymentMethod::where('code', 'cash')->update(['is_active' => false]);

        $screen->call('place')->assertHasErrors('payment')->assertSet('step', 'payment');

        $this->assertSame(0, Order::count());
    }

    /** A product withdrawn mid-call takes the operator back to the items. */
    public function test_a_product_withdrawn_mid_call_stops_the_order(): void
    {
        $product = $this->product(price: 500);
        $screen = $this->ready($product);

        $product->update(['status' => Product::STATUS_ARCHIVED]);

        $screen->call('place')->assertSet('step', 'items');

        $this->assertSame(0, Order::count());
    }

    /* ------------------------------------------------------------------ the customer */

    public function test_an_account_is_attached(): void
    {
        $customer = User::create([
            'name' => 'ნინო', 'email' => 'nino@iapi.ge',
            'password' => bcrypt('x'), 'phone' => '995599000000',
        ]);

        $this->screen()
            ->call('useCustomer', $customer->id)
            ->assertSet('userId', $customer->id)
            ->assertSet('name', 'ნინო')
            ->assertSet('email', 'nino@iapi.ge');
    }

    public function test_an_order_for_an_account_belongs_to_it(): void
    {
        $customer = User::create([
            'name' => 'ნინო', 'email' => 'nino@iapi.ge', 'password' => bcrypt('x'),
        ]);

        $this->ready($this->product(price: 500))
            ->call('useCustomer', $customer->id)
            ->set('cityId', $this->city->id)
            ->set('address', 'ვაჟა-ფშაველას 76')
            ->call('place');

        $this->assertSame($customer->id, Order::first()->user_id);
    }

    public function test_a_walk_in_order_belongs_to_nobody(): void
    {
        $this->ready($this->product(price: 500))->call('place');

        $this->assertNull(Order::first()->user_id);
    }

    /* ------------------------------------------------------------------ from a call */

    public function test_a_callback_fills_the_form_in(): void
    {
        $product = $this->product(price: 500);

        $call = CallbackRequest::create([
            'name' => 'დავით', 'phone' => '995577111222',
            'product_id' => $product->id, 'status' => CallbackRequest::NEW,
        ]);

        Livewire::actingAs($this->admin())->test(Create::class, ['callbackId' => $call->id])
            ->assertSet('name', 'დავით')
            ->assertSet('phone', '995577111222')
            ->assertSet('qty', [$product->id => 1]);
    }

    /**
     * The browser's identifiers follow the order, not the operator's.
     *
     * Meta sees a Lead when the number is left and a Purchase when the order
     * is paid, and only joins them if the Purchase carries what the customer's
     * browser had. Written from the admin it would carry the operator's
     * machine instead, and the sale would be credited to nobody.
     */
    public function test_the_calls_pixel_data_is_carried_over(): void
    {
        $product = $this->product(price: 500);

        $call = CallbackRequest::create([
            'name' => 'დავით', 'phone' => '995577111222',
            'product_id' => $product->id, 'status' => CallbackRequest::NEW,
            'fbp' => 'fb.1.123.456', 'fbc' => 'fb.1.789.abc',
            'ip' => '31.146.0.1', 'user_agent' => 'Mozilla/5.0 (iPhone)',
            'source_url' => 'https://iapi.ge/ka/product/x', 'consented' => true,
        ]);

        Livewire::actingAs($this->admin())->test(Create::class, ['callbackId' => $call->id])
            ->set('cityId', $this->city->id)
            ->set('address', 'ვაჟა-ფშაველას 76')
            ->set('payment', 'cash')
            ->call('place');

        $snapshot = OrderPixelData::where('order_id', Order::first()->id)->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('fb.1.123.456', $snapshot->fbp);
        $this->assertSame('fb.1.789.abc', $snapshot->fbc);
        $this->assertSame('31.146.0.1', $snapshot->ip);
        $this->assertSame('Mozilla/5.0 (iPhone)', $snapshot->user_agent);
        $this->assertTrue((bool) $snapshot->consented);
    }

    public function test_the_call_is_closed_against_the_order(): void
    {
        $product = $this->product(price: 500);

        $call = CallbackRequest::create([
            'name' => 'დავით', 'phone' => '995577111222',
            'product_id' => $product->id, 'status' => CallbackRequest::NEW,
        ]);

        Livewire::actingAs($this->admin())->test(Create::class, ['callbackId' => $call->id])
            ->set('cityId', $this->city->id)
            ->set('address', 'ვაჟა-ფშაველას 76')
            ->set('payment', 'cash')
            ->call('place');

        $call->refresh();

        $this->assertSame(Order::first()->id, $call->order_id);
        $this->assertSame(CallbackRequest::CALLED, $call->status);
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

        $this->city = DeliveryCity::create(['is_active' => true, 'sort_order' => 1, 'fee' => 5]);
        $this->city->saveTranslations(['ka' => ['name' => 'თბილისი']]);

        PaymentMethod::create(['code' => 'cash', 'is_active' => true, 'is_online' => false, 'sort_order' => 1]);
    }

    protected function screen()
    {
        return Livewire::actingAs($this->admin())->test(Create::class);
    }

    /** The screen at a given step, with the steps before it answered. */
    protected function atStep(string $step)
    {
        $screen = $this->screen()
            ->set('name', 'გიორგი')
            ->set('phone', '995555123456');

        if ($step === 'items') {
            return $screen->call('next');
        }

        return $screen
            ->set('qty', [$this->product(price: 500)->id => 1])
            ->call('next')
            ->call('next');
    }

    /** Every question answered, waiting on the last button. */
    protected function ready(Product $product)
    {
        return $this->screen()
            ->set('name', 'გიორგი')
            ->set('phone', '995555123456')
            ->set('qty', [$product->id => 1])
            ->set('delivery', 'courier')
            ->set('cityId', $this->city->id)
            ->set('address', 'ვაჟა-ფშაველას 76')
            ->set('payment', 'cash')
            ->set('step', 'payment');
    }

    protected function admin(): User
    {
        return User::create([
            'name' => 'ოპერატორი',
            'email' => 'op'.uniqid().'@iapi.ge',
            'password' => bcrypt('secret'),
            'is_admin' => true,
        ]);
    }

    protected function product(float $price, int $stock = 5): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $this->category->id,
            'price' => $price,
            'stock' => $stock,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => 'მაცივარი', 'slug' => 'macivari-'.$product->id]]);

        return $product->fresh();
    }
}
