<?php

namespace App\Livewire\Pages;

use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\PaymentMethod;
use App\Services\Facebook\Pixel;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * The screen a customer lands on straight after paying.
 *
 * It is read once, often on a phone, often while still deciding whether this
 * shop can be trusted — so it answers the three questions of that moment: did
 * it go through, what happens next, and how do I reach you.
 */
class OrderPlaced extends Component
{
    public Order $order;

    public function mount(string $number): void
    {
        $order = Order::where('number', $number)
            ->with('items')
            ->firstOrFail();

        // an order number is short enough to guess, so a stranger gets nothing
        // (the rule itself lives in the view-order gate, beside the invoice's)
        abort_unless(Gate::allows('view-order', $order), 404);

        $this->order = $order;

        $this->trackPurchase($order);
    }

    /**
     * The browser half of Purchase.
     *
     * It reuses the event_id the snapshot carries, so whichever half Meta sees
     * first wins and a refresh of this page cannot report a second sale. An
     * unpaid card order is deliberately silent: PaymentManager reports that one
     * when the bank confirms it, and reporting it here would count a sale the
     * shop has not made.
     */
    protected function trackPurchase(Order $order): void
    {
        $snapshot = OrderPixelData::where('order_id', $order->id)->first();

        if (! $snapshot || ! $snapshot->consented) {
            return;
        }

        $method = PaymentMethod::where('code', $order->payment)->first();

        if (! $order->is_paid && $method?->is_online) {
            return;
        }

        $this->dispatch('pixel', [
            'event' => 'Purchase',
            'id' => $snapshot->event_id,
            'data' => app(Pixel::class)->purchaseData($order->loadMissing('items')),
        ]);
    }

    public function render()
    {
        return view('livewire.pages.order-placed')
            ->layout('layouts.app', [
                'title' => __('order.placed_title', ['number' => $this->order->number]),
                // names a person and their address: never an index entry
                'seo' => ['robots' => 'noindex, nofollow'],
            ]);
    }
}
