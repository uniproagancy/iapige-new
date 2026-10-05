<?php

namespace App\Livewire\Pages;

use App\Models\Order;
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
    }

    public function render()
    {
        return view('livewire.pages.order-placed')
            ->layout('layouts.app', [
                'title' => __('order.placed_title', ['number' => $this->order->number]),
            ]);
    }
}
