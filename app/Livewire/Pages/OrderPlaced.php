<?php

namespace App\Livewire\Pages;

use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\PaymentMethod;
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

                'seo' => ['robots' => 'noindex, nofollow'],
            ]);
    }
}
