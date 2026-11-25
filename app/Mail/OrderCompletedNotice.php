<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
 | The shop's own copy of an order the moment it is marked completed, sent
 | to the addresses in Settings (shop.completed_emails). It goes out after
 | the response rather than through the queue: nothing else on the site
 | sends mail yet, and a worker nobody watches would swallow it.
 */
class OrderCompletedNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public ?string $by = null) {}

    /** @return list<string> */
    public static function recipients(): array
    {
        $list = preg_split('/[\s,;]+/', (string) config('shop.completed_emails', ''), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($list, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
    }

    public static function dispatchFor(Order $order): void
    {
        $recipients = self::recipients();

        if (! $recipients) {
            return;
        }

        $id = $order->id;
        $by = auth()->user()?->name;

        /* A mail server that is down must not undo, or even slow, the
           click that completed the order -- it is only written to the log. */
        dispatch(function () use ($id, $by, $recipients) {
            $order = Order::find($id);

            if (! $order) {
                return;
            }

            try {
                Mail::to($recipients)->send(new self($order, $by));
            } catch (\Throwable $e) {
                Log::error('order completed mail failed', [
                    'order' => $order->number,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.completed_subject', [
                'number' => $this->order->number,
                'total' => money($this->order->total),
            ]),
        );
    }

    public function content(): Content
    {
        $order = $this->order->loadMissing(['items', 'deliveryCity']);

        return new Content(
            view: 'emails.order-completed',
            with: [
                'order' => $order,
                'by' => $this->by,
                'payment' => PaymentMethod::where('code', $order->payment)->first()?->name ?? $order->payment,
                'url' => route('admin.orders.show', $order),
            ],
        );
    }
}
