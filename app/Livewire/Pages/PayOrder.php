<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\SendsToBank;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentManager;
use Livewire\Component;

class PayOrder extends Component
{
    use SendsToBank;

    public Order $order;

    public ?string $refused = null;

    public function mount(string $number): void
    {
        $order = Order::where('number', $number)->with('items')->firstOrFail();

        $this->order = $order;
        $this->refused = $this->refusal($order);
    }

    protected function refusal(Order $order): ?string
    {
        if (! request()->hasValidSignature()) {
            return 'expired';
        }

        if ($order->is_paid) {
            return 'paid';
        }

        if ($order->status === Order::CANCELLED) {
            return 'closed';
        }

        return $this->payable() ? null : 'offline';
    }

    protected function payable(): ?PaymentMethod
    {
        $method = $this->method();

        return $method
            && $method->is_online
            && $method->driver
            && app(PaymentManager::class)->has($method->driver)
            ? $method
            : null;
    }

    protected function method(): ?PaymentMethod
    {
        return PaymentMethod::active()->where('code', $this->order->payment)->first();
    }

    public function pay()
    {

        $this->order->refresh();
        $this->refused = $this->refusalNow();

        if ($this->refused) {
            return null;
        }

        return $this->sendToBank($this->order, $this->method());
    }

    protected function refusalNow(): ?string
    {
        if ($this->order->is_paid) {
            return 'paid';
        }

        if ($this->order->status === Order::CANCELLED) {
            return 'closed';
        }

        return $this->payable() ? null : 'offline';
    }

    public function render()
    {
        return view('livewire.pages.pay-order', [
            'method' => $this->method(),
        ])->layout('layouts.app', [
            'title' => __('pay.title', ['number' => $this->order->number]),

            'seo' => ['robots' => 'noindex, nofollow'],
        ]);
    }
}
