<?php

namespace Tests\Feature;

use App\Models\DeliveryCity;
use App\Models\Language;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An order could not be marked paid.
 *
 * Order::$fillable did not list the payment columns, and mass assignment
 * discards what it does not recognise without a word. So every write that
 * settles a payment went nowhere: the bank confirmed, PaymentManager marked
 * its transaction successful and wrote the event, and the order stayed
 * unpaid — the shop's own record of whether it had been paid was always "no".
 * The admin's "mark as paid" button did the same nothing and reported success.
 *
 * These assert the columns, not the callers, because that is where the fault
 * was and because the same hole opens again for every column added later.
 */
class OrderPaymentFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_can_be_marked_paid(): void
    {
        $order = $this->order();

        $order->update(['is_paid' => true, 'paid_at' => now(), 'payment_status' => 'paid']);

        $order = $order->fresh();

        $this->assertTrue($order->is_paid);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_an_instalment_term_is_recorded(): void
    {
        $order = $this->order();

        $order->update(['installment_months' => 12]);

        $this->assertSame(12, $order->fresh()->installment_months);
    }

    /** A payment that failed must be able to say so, not merely stay pending. */
    public function test_a_failed_payment_is_recorded(): void
    {
        $order = $this->order();

        $order->update(['payment_status' => 'failed']);

        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertFalse($order->fresh()->is_paid);
    }

    /* ------------------------------------------------------------------ the city */

    /**
     * The city an order was priced for, kept as more than a name.
     *
     * Checkout has always passed delivery_city_id; there was no column to
     * receive it and no fillable entry to let it through, so the shipping fee
     * could never be recalculated. The relation was named city() too, which
     * the string column of that name shadowed, so it never loaded either.
     */
    public function test_the_delivery_city_is_kept(): void
    {
        Language::insert([['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1]]);
        Language::flushCache();

        $city = DeliveryCity::create(['is_active' => true, 'sort_order' => 1, 'fee' => 5]);
        $city->saveTranslations(['ka' => ['name' => 'თბილისი']]);

        $order = $this->order(['delivery_city_id' => $city->id]);

        $this->assertSame($city->id, $order->fresh()->delivery_city_id);
        $this->assertSame($city->id, $order->fresh()->deliveryCity->id);
    }

    public function test_an_order_without_a_city_has_no_zone(): void
    {
        $this->assertNull($this->order()->deliveryCity);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'number' => Order::nextNumber(),
            'name' => 'ოპერატორი',
            'phone' => '995500000000',
            'subtotal' => 100,
            'shipping' => 0,
            'total' => 100,
        ], $attributes));
    }
}
