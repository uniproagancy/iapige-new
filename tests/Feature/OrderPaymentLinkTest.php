<?php

namespace Tests\Feature;

use App\Livewire\Admin\Orders\Show;
use App\Livewire\Pages\PayOrder;
use App\Models\Category;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Paying for an order the operator took on the telephone.
 *
 * The operator cannot take a card down the line, so the order is placed
 * unpaid and the payment is handed over afterwards — either by sending the
 * customer a link, or by opening it and paying for them while they are still
 * on the call. One page serves both, because they are the same payment.
 *
 * The link is signed and it expires, and that is the part these tests are
 * really about: an order number is four digits, and a page that spends money
 * must not be reachable by counting.
 */
class OrderPaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ when it is offered */

    public function test_an_online_order_gets_a_link(): void
    {
        $order = $this->order('bog-card');

        $this->assertNotNull($this->screen($order)->instance()->payLink());
    }

    /** Cash on delivery has nothing for a bank to do. */
    public function test_a_cash_order_gets_none(): void
    {
        $order = $this->order('cash');

        $this->assertNull($this->screen($order)->instance()->payLink());
    }

    public function test_an_order_already_paid_gets_none(): void
    {
        $order = $this->order('bog-card');
        $order->update(['is_paid' => true, 'paid_at' => now()]);

        $this->assertNull($this->screen($order)->instance()->payLink());
    }

    public function test_a_cancelled_order_gets_none(): void
    {
        $order = $this->order('bog-card');
        $order->update(['status' => 'cancelled']);

        $this->assertNull($this->screen($order)->instance()->payLink());
    }

    /** A method switched off in the admin stops being payable. */
    public function test_an_inactive_method_gets_none(): void
    {
        $order = $this->order('bog-card');
        PaymentMethod::where('code', 'bog-card')->update(['is_active' => false]);

        $this->assertNull($this->screen($order)->instance()->payLink());
    }

    public function test_the_screen_shows_the_link_and_the_button(): void
    {
        $this->screen($this->order('bog-card'))
            ->assertSee(__('admin.pay_online'))
            ->assertSee(__('admin.pay_here'));
    }

    /* ------------------------------------------------------------------ the page */

    public function test_the_signed_link_opens_the_page(): void
    {
        $order = $this->order('bog-card');

        $this->get($this->link($order))
            ->assertOk()
            ->assertSee(__('pay.go'));
    }

    /**
     * An order number is short enough to guess.
     *
     * Without the signature the page would take a payment for anybody's
     * order, from anybody who counted up to its number.
     */
    public function test_an_unsigned_link_is_refused(): void
    {
        $order = $this->order('bog-card');

        $this->get(route('order.pay', $order->number))
            ->assertOk()
            ->assertSee(__('pay.refused.expired_title'))
            ->assertDontSee(__('pay.go'));
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $order = $this->order('bog-card');
        $other = $this->order('bog-card');

        // the signature was made for one order; pointing it at another breaks it
        $link = str_replace($order->number, $other->number, $this->link($order));

        $this->get($link)->assertSee(__('pay.refused.expired_title'));
    }

    public function test_an_expired_link_says_so(): void
    {
        $order = $this->order('bog-card');

        $link = URL::temporarySignedRoute('order.pay', now()->subMinute(), [
            'number' => $order->number,
        ]);

        $this->get($link)->assertSee(__('pay.refused.expired_title'));
    }

    /** Clicked twice, after the money arrived the first time. */
    public function test_an_order_already_paid_is_not_charged_again(): void
    {
        $order = $this->order('bog-card');
        $link = $this->link($order);

        $order->update(['is_paid' => true, 'paid_at' => now()]);

        $this->get($link)
            ->assertSee(__('pay.refused.paid_title'))
            ->assertDontSee(__('pay.go'));
    }

    public function test_a_cancelled_order_is_not_payable(): void
    {
        $order = $this->order('bog-card');
        $link = $this->link($order);

        $order->update(['status' => 'cancelled']);

        $this->get($link)->assertSee(__('pay.refused.closed_title'));
    }

    /**
     * The page may have sat open while the order changed underneath it.
     *
     * This is the request that spends the money, so it checks again rather
     * than trusting what mount() found.
     */
    public function test_paying_checks_the_order_again(): void
    {
        $order = $this->order('bog-card');

        $page = Livewire::withUrlParams(['number' => $order->number])
            ->test(PayOrder::class, ['number' => $order->number]);

        $order->update(['is_paid' => true, 'paid_at' => now()]);

        $page->call('pay')->assertSet('refused', 'paid');
    }

    /* ------------------------------------------------------------------ completing the sale */

    /**
     * An order the operator marks completed is a sale that happened.
     *
     * Meta never sees these otherwise: they are taken on the telephone, with
     * no browser to fire anything, so the shop's own calls look like nothing
     * beside the advertising that produced them.
     */
    public function test_completing_an_order_reports_the_sale(): void
    {
        Http::fake(fn () => Http::response(['events_received' => 1]));

        $order = $this->order('cash');

        $this->screen($order)->set('status', 'completed')->call('changeStatus');

        Http::assertSent(fn ($r) => $r['data'][0]['event_name'] === 'Purchase'
            && $r['data'][0]['custom_data']['order_id'] === $order->number);
    }

    /** Walked back and forth through completed all afternoon: one sale. */
    public function test_the_sale_is_reported_once(): void
    {
        Http::fake(fn () => Http::response(['events_received' => 1]));

        $order = $this->order('cash');
        $screen = $this->screen($order);

        $screen->set('status', 'completed')->call('changeStatus');
        $screen->set('status', 'shipped')->call('changeStatus');
        $screen->set('status', 'completed')->call('changeStatus');

        Http::assertSentCount(1);
    }

    public function test_another_status_reports_nothing(): void
    {
        Http::fake(fn () => Http::response(['events_received' => 1]));

        $this->screen($this->order('cash'))->set('status', 'packed')->call('changeStatus');

        Http::assertNothingSent();
    }

    /** One the bank already reported is found sent and left alone. */
    public function test_an_order_reported_by_its_bank_is_not_reported_twice(): void
    {
        Http::fake(fn () => Http::response(['events_received' => 1]));

        $order = $this->order('bog-card');

        OrderPixelData::create([
            'order_id' => $order->id,
            'event_id' => 'pur_1',
            'consented' => true,
            'sent_at' => now()->subHour(),
        ]);

        $this->screen($order)->set('status', 'completed')->call('changeStatus');

        Http::assertNothingSent();
    }

    /** Meta being slow or broken must not undo a status the operator saw saved. */
    public function test_a_refused_report_does_not_lose_the_status(): void
    {
        Http::fake(fn () => Http::response('no', 500));

        $order = $this->order('cash');

        $this->screen($order)->set('status', 'completed')->call('changeStatus');

        $this->assertSame('completed', $order->fresh()->status);
    }

    /* ------------------------------------------------------------------ the pictures */

    public function test_the_order_shows_its_products_photographs(): void
    {
        $order = $this->order('cash');
        $product = Product::first();

        $product->images()->create(['path' => 'products/kettle.jpg', 'sort_order' => 0]);

        $this->screen($order->fresh())->assertSee('products/kettle.jpg');
    }

    /** A product deleted since leaves a line that still reads correctly. */
    public function test_a_line_without_a_product_still_shows(): void
    {
        $order = $this->order('cash');

        $order->items()->update(['product_id' => null]);

        $this->screen($order->fresh())->assertOk()->assertSee('ჩაიდანი');
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        config([
            'services.facebook.pixel_id' => '111122223333',
            'services.facebook.access_token' => 'test-token',
            'services.facebook.test_event_code' => null,
        ]);

        PaymentMethod::insert([
            ['code' => 'cash', 'driver' => null, 'is_active' => true, 'is_online' => false, 'sort_order' => 1],
            ['code' => 'bog-card', 'driver' => 'bog-card', 'is_active' => true, 'is_online' => true, 'sort_order' => 2],
        ]);
    }

    protected function order(string $payment): Order
    {
        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $category->id,
            'price' => 300, 'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $order = Order::create([
            'number' => Order::nextNumber(),
            'status' => 'new',
            'name' => 'გიორგი', 'phone' => '599123456',
            'delivery' => 'courier', 'payment' => $payment,
            'subtotal' => 300, 'shipping' => 0, 'total' => 300,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku, 'name' => 'ჩაიდანი',
            'price' => 300, 'qty' => 1, 'sum' => 300,
        ]);

        return $order->fresh('items');
    }

    protected function screen(Order $order)
    {
        $admin = User::create([
            'name' => 'operator', 'email' => 'o'.uniqid().'@iapi.ge',
            'password' => bcrypt('x'), 'is_admin' => true,
        ]);

        return Livewire::actingAs($admin)->test(Show::class, ['order' => $order]);
    }

    protected function link(Order $order): string
    {
        return URL::temporarySignedRoute('order.pay', now()->addDays(Show::LINK_DAYS), [
            'number' => $order->number,
        ]);
    }
}
