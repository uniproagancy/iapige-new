<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Told when the order moves.
 *
 * Only the steps a customer would act on are mailed — packed and shipped mean
 * "be reachable", completed and cancelled close the matter. "Confirmed" is
 * left out: an operator has just rung them about it.
 */
class OrderStatusChanged extends Mailable
{
    use Queueable, SerializesModels;

    /** The statuses worth an email of their own. */
    public const NOTIFY = ['shipped', 'completed', 'cancelled'];

    public function __construct(public Order $order, public string $status)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.status_subject_'.$this->status, ['number' => $this->order->number]),
            replyTo: [config('shop.email')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-status',
            with: [
                'order'  => $this->order->loadMissing(['items', 'city']),
                'status' => $this->status,
                'url'    => route('order', $this->order->number),
            ],
        );
    }
}
