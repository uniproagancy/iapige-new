<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Payments\Drivers\CredoPartInstallment;

class CredoPartController extends Controller
{
    public function form(string $number, CredoPartInstallment $credo)
    {
        $order = Order::with('items')->where('number', $number)->firstOrFail();

        abort_unless($this->mayPay($order), 404);

        if ($order->is_paid) {
            return redirect()->route('order', $order->number);
        }

        return view('payments.credo_part', [
            'order' => $order,
            'action' => config('credo_part.widget_url'),
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
