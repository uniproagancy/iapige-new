<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invoice for an order that will be paid by bank transfer.
 *
 * It is the only way such a customer can pay us at all, so it carries the
 * account details, the reference to quote, and the date after which the goods
 * go back on sale.
 */
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
