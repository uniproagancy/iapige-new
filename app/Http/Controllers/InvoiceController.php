<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Invoices\InvoicePdf;
use App\Support\BankAccounts;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function show(string $number)
    {
        $order = Order::with('items')->where('number', $number)->firstOrFail();

        abort_unless(Gate::allows('view-order', $order), 404);

        return view('invoices.show', [
            'order' => $order,
            'company' => config('shop.company'),
            'accounts' => BankAccounts::all(),
            'dueAt' => $order->created_at->copy()->addDays((int) config('shop.invoice_valid_days', 3)),
        ]);
    }

    public function download(Order $order, InvoicePdf $invoices): Response
    {
        return $invoices->for($order)->download($invoices->filename($order));
    }

    public function preview(Order $order, InvoicePdf $invoices): Response
    {
        return $invoices->for($order)->stream($invoices->filename($order));
    }
}
