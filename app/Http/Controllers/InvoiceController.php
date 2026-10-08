<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Gate;

/**
 * The printable invoice.
 *
 * A page rather than a generated PDF: every browser prints one, it stays
 * readable on a phone, and it needs no rendering library on the server. The
 * customer's bank will accept a printout as readily as a file.
 */
class InvoiceController extends Controller
{
    public function show(string $number)
    {
        $order = Order::with('items')->where('number', $number)->firstOrFail();

        // an order number is short enough to guess, and this page names a person
        abort_unless(Gate::allows('view-order', $order), 404);

        return view('invoices.show', [
            'order' => $order,
            'company' => config('shop.company'),
            'dueAt' => $order->created_at->copy()->addDays((int) config('shop.invoice_valid_days', 3)),
        ]);
    }
}
