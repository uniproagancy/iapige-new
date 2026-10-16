<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderInvoice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('invoice.subject', ['number' => $this->order->number]),
            replyTo: [config('shop.email')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice',
            with: [
                'order' => $this->order->loadMissing('items'),
                'company' => config('shop.company'),
                'dueAt' => $this->order->created_at->copy()->addDays((int) config('shop.invoice_valid_days', 3)),
                'url' => route('invoice', $this->order->number),
            ],
        );
    }
}
