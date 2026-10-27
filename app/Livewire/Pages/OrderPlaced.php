<?php

namespace App\Livewire\Pages;

use App\Models\Order;
use App\Services\Facebook\Pixel;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class OrderPlaced extends Component
{
    public Order $order;

    public function mount(string $number): void
    {
        $order = Order::where('number', $number)
            ->with('items')
            ->firstOrFail();

        abort_unless(Gate::allows('view-order', $order), 404);

        $this->order = $order;

        $this->trackPurchase($order);
    }

    protected function trackPurchase(Order $order): void
    {
        if ($event = app(Pixel::class)->browserPurchase($order)) {
            $this->dispatch('pixel', $event);
        }
    }

    public function render()
    {
        return view('livewire.pages.order-placed')
            ->layout('layouts.app', [
                'title' => __('order.placed_title', ['number' => $this->order->number]),

                'seo' => ['robots' => 'noindex, nofollow'],
            ]);
    }
}
