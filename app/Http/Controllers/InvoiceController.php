<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    public function show(string $number)
    {
        $order = Order::with('items')->where('number', $number)->firstOrFail();

        abort_unless(Gate::allows('view-order', $order), 404);

        return view('invoices.show', [
            'order' => $order,
            'company' => config('shop.company'),
            'dueAt' => $order->created_at->copy()->addDays((int) config('shop.invoice_valid_days', 3)),
        ]);
    }
}
