<?php

namespace App\Livewire\Pages;

use App\Models\Order;
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
        abort_unless($this->mayView($order), 404);

        $this->order = $order;
    }

    /**
     * Its owner, or whoever just placed it — the session remembers that for an
     * hour, which is as long as this page is ever useful to a guest.
     */
    protected function mayView(Order $order): bool
    {
        if (auth()->check() && $order->user_id === auth()->id()) {
            return true;
        }

        return in_array($order->id, (array) session('placed_orders', []), true);
    }

    public function render()
    {
        return view('livewire.pages.order-placed')
            ->layout('layouts.app', [
                'title' => __('order.placed_title', ['number' => $this->order->number]),
            ]);
    }
}
