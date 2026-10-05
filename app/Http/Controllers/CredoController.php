<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Payments\Drivers\CredoInstallment;

/**
 * The page that hands the customer to Credo's widget.
 *
 * Credo takes a form post rather than a redirect, so this page exists only to
 * submit one — and it rebuilds the payload rather than trusting what arrives
 * in the URL, which is just an order number.
 */
class CredoController extends Controller
{
    public function form(string $number, CredoInstallment $credo)
    {
        $order = Order::with('items')->where('number', $number)->firstOrFail();

        abort_unless($this->mayPay($order), 404);

        if ($order->is_paid) {
            return redirect()->route('order', $order->number);
        }

        return view('payments.credo', [
            'order'  => $order,
            'action' => config('credo.widget_url'),
            'data'   => json_encode($credo->payload($order), JSON_UNESCAPED_UNICODE),
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
