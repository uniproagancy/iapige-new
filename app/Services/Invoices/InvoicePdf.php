<?php

namespace App\Services\Invoices;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWriter;
use Illuminate\Support\Str;

class InvoicePdf
{
    public function for(Order $order): PdfWriter
    {
        $order->loadMissing('items');

        return Pdf::setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'dejavu sans',
                'chroot' => base_path(),
            ])
            ->loadView('invoices.pdf', [
                'order' => $order,
                'company' => config('shop.company'),
                'dueAt' => $order->created_at->copy()->addDays((int) config('shop.invoice_valid_days', 3)),
                'regular' => $this->font('Linotype-HelveticaNeueLTGEO55Roman.ttf'),
                'bold' => $this->font('Linotype-HelveticaNeueLTGEO75Bold.ttf'),
            ]);
    }

    public function filename(Order $order): string
    {
        return Str::slug(__('invoice.title', ['number' => $order->number]), '-').'.pdf';
    }

    protected function font(string $file): string
    {
        return str_replace('\\', '/', resource_path('dashboard/fonts/'.$file));
    }
}
