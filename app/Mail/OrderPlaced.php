<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The confirmation a customer gets the moment an order exists.
 *
 * Its job is not to sell anything: it is proof that the money and the details
 * arrived somewhere real, which is the only thing a person wants to know in
 * the minute after paying a shop they have not used before.
 */
class OrderPlaced extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.placed_subject', ['number' => $this->order->number]),
            replyTo: [config('shop.email')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-placed',
            with: [
                'order' => $this->order->loadMissing(['items', 'city']),
                'url' => route('order', $this->order->number),
                'days' => config('shop.delivery_days', 2),
            ],
        );
    }
}
