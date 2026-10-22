<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Payments\Drivers\CredoInstallment;
use App\Services\Payments\Drivers\CredoSplit;

class CredoController extends Controller
{
    public function form(string $number, CredoInstallment $credo)
    {
        return $this->handOver($number, $credo);
    }

    public function split(string $number, CredoSplit $credo)
    {
        return $this->handOver($number, $credo);
    }

    protected function handOver(string $number, CredoInstallment $credo)
    {
        $order = Order::with('items')->where('number', $number)->firstOrFail();

        abort_unless($this->mayPay($order), 404);

        if ($order->is_paid) {
            return redirect()->route('order', $order->number);
        }

        return view('payments.credo', [
            'order' => $order,
            'action' => $credo->widgetUrl(),
            'field' => $credo->fieldName(),
            'label' => $credo->label(),
            'data' => json_encode($credo->payload($order), JSON_UNESCAPED_UNICODE),
        ]);
    }

    protected function mayPay(Order $order): bool
    {
        if (auth()->check() && $order->user_id === auth()->id()) {
            return true;
        }

        return in_array($order->id, (array) session('placed_orders', []), true);
    }
}
