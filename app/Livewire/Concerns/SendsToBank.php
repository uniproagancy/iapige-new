<?php

namespace App\Livewire\Concerns;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\Payments\Drivers\BogInstallment;
use App\Services\Payments\PaymentManager;

trait SendsToBank
{
    protected function sendToBank(Order $order, ?PaymentMethod $method)
    {
        $driver = $method?->driver;
        $payments = app(PaymentManager::class);

        if (! $driver || ! $payments->has($driver)) {
            return $this->fallBackToCall($order, "no driver for payment method [{$order->payment}]");
        }

        $minimum = (float) ($method->min_total ?? 0);

        if ($minimum > 0 && (float) $order->total < $minimum) {
            $this->dispatch('toast', type: 'error', message: __('checkout.method_min', [
                'amount' => money($minimum),
            ]));

            return $this->redirect(route('order', $order->number), navigate: false);
        }

        if ($driver === 'bog-installment') {
            return $this->openCalculator($order, $method);
        }

        try {
            return redirect()->away($payments->start($order, $driver));
        } catch (\Throwable $e) {
            report($e);

            return $this->fallBackToCall($order, $e->getMessage());
        }
    }

    protected function openCalculator(Order $order, PaymentMethod $method)
    {
        $minimum = (float) ($method->min_total ?? config('bog.installment.min_total', 100));

        if ((float) $order->total < $minimum) {
            $this->dispatch('toast', type: 'error', message: __('checkout.installment_min', [
                'amount' => money($minimum),
            ]));

            return $this->redirect(route('order', $order->number), navigate: false);
        }

        $zero = $method->installment_type === BogInstallment::ZERO;
        $fee = $zero ? 0.0 : (float) config('bog.installment.handling_fee', 0.05);

        $this->dispatch($zero ? 'bog:installment-part' : 'bog:installment',
            amount: round((float) $order->total * (1 + $fee), 2),
            url: route('payment.bog.installment', $order->number),
        );

        return null;
    }

    protected function fallBackToCall(Order $order, string $reason)
    {
        $order->events()->create([
            'type' => 'payment',
            'note' => __('order.payment_start_failed').' — '.$reason,
        ]);

        session()->flash('toast', __('checkout.payment_failed'));

        return $this->redirect(route('order', $order->number), navigate: false);
    }
}
