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
                self::record($order, $recipients, $e);

                return;
            }

            self::record($order, $recipients);
        })->afterResponse();
    }

    /* Written to logs/mail.log and to the order's own history, so whoever
       completed it can see on the order page whether the mail went out.
       "Sent" means the mail server accepted it, not that it was read. */
    protected static function record(Order $order, array $recipients, ?\Throwable $error = null): void
    {
        $to = implode(', ', $recipients);

        if ($error) {
            Log::channel('mail')->error('order completed mail failed', [
                'order' => $order->number,
                'to' => $recipients,
                'reason' => self::reason($error),
                'error' => get_class($error).': '.$error->getMessage(),
                'previous' => $error->getPrevious()?->getMessage(),
                'mailer' => self::settings(),
            ]);
        } else {
            Log::channel('mail')->info('order completed mail sent', [
                'order' => $order->number,
                'to' => $recipients,
            ]);
        }

        try {
            $order->events()->create([
                'type' => 'note',
                'note' => $error
                    ? __('mail.completed_log_failed', [
                        'to' => $to,
                        'reason' => self::reason($error),
                        'error' => mb_substr($error->getMessage(), 0, 200),
                    ])
                    : __('mail.completed_log_sent', ['to' => $to]),
            ]);
        } catch (\Throwable) {
            // The log line above already holds the outcome.
        }
    }

    /* What the mailer was set to when it failed -- never the password, only
       whether there is one -- so a bad .env shows in the log itself. */
    protected static function settings(): array
    {
        $mailer = (string) config('mail.default');
        $config = (array) config('mail.mailers.'.$mailer, []);

        return [
            'mailer' => $mailer,
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'scheme' => $config['scheme'] ?? null,
            'username' => filled($config['username'] ?? null) ? 'set' : 'empty',
            'password' => filled($config['password'] ?? null) ? 'set' : 'empty',
            'from' => config('mail.from.address'),
        ];
    }

    /* The mail server's own words are for a developer; the operator who
       completed the order gets which .env line to look at. */
    protected static function reason(\Throwable $error): string
    {
        $config = self::settings();
        $message = mb_strtolower($error->getMessage().' '.$error->getPrevious()?->getMessage());

        return match (true) {
            $config['mailer'] === 'smtp' && blank($config['host']) => __('mail.reason_no_host'),
            $config['mailer'] === 'log' => __('mail.reason_log_mailer'),
            str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known') => __('mail.reason_bad_host'),
            str_contains($message, '535') || str_contains($message, 'authentication') || str_contains($message, 'username and password') => __('mail.reason_auth'),
            str_contains($message, 'timed out') || str_contains($message, 'refused') => __('mail.reason_port'),
            str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate') => __('mail.reason_tls'),
            str_contains($message, '550') || str_contains($message, '553') || str_contains($message, 'sender') => __('mail.reason_sender'),
            default => __('mail.reason_unknown'),
        };
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
