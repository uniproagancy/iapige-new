<?php

namespace App\Services\Payments\Drivers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentDriver;
use App\Support\Redact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CredoInstallment implements PaymentDriver
{
    /* Credo sells more than one product through the same widget. They differ
       only by the shop they are booked against, so the rest is shared. */
    public function configKey(): string
    {
        return 'credo';
    }

    public function driverName(): string
    {
        return 'credo-installment';
    }

    public function formRoute(): string
    {
        return 'payment.credo.form';
    }

    public function shopId(): string
    {
        return (string) config($this->configKey().'.merchant_id');
    }

    public function widgetUrl(): string
    {
        return (string) config($this->configKey().'.widget_url');
    }

    public function fieldName(): string
    {
        return (string) config($this->configKey().'.field', 'credoinstallment');
    }

    public function label(): string
    {
        return (string) config($this->configKey().'.label', 'Credo');
    }

    protected function fee(): float
    {
        return (float) config($this->configKey().'.fee', 0.10);
    }

    public function start(Order $order, array $options = []): PaymentTransaction
    {
        return PaymentTransaction::create([
            'order_id' => $order->id,
            'driver' => $this->driverName(),

            'external_id' => (string) $order->number,
            'amount' => $this->total($order),
            'status' => PaymentTransaction::PENDING,
            'request' => $this->payload($order),

            'redirect_url' => route($this->formRoute(), $order->number),
        ]);
    }

    /* What Credo calls each step of an application. Only one of them means
       the money has moved. */
    public const FINISHED = 5;

    public const SIGNED = 12;

    public const REJECTED = 6;

    public const CANCELLED = 7;

    public function statusUrl(): string
    {
        return (string) config($this->configKey().'.status_url');
    }

    protected function secret(): string
    {
        return (string) config($this->configKey().'.secret');
    }

    public function hashFor(string $orderCode): string
    {
        return md5($this->shopId().$orderCode.$this->secret());
    }

    /*
     | Credo has no callback that can be trusted on its own, so the shop asks
     | where the application stands. Only "finished" means the funds have been
     | transferred; a signed contract says to dispatch the goods, which is the
     | shop's cue but not a payment.
     */
    public function confirm(PaymentTransaction $transaction): bool
    {
        /* Without a secret there is nothing to sign the question with, and
           Credo issued one for the split product only. Said plainly, so the
           sweeper's silence is not mistaken for a refusal: these are settled
           by hand until Credo hands a secret over. */
        if (! $this->shopId() || ! $this->secret()) {
            Log::channel('payments')->info('credo cannot be asked, so nothing is confirmed here', [
                'driver' => $this->driverName(),
                'order' => $transaction->external_id,
                'missing' => ! $this->shopId() ? 'merchant id' : 'secret',
            ]);

            return false;
        }

        $code = (string) $transaction->external_id;

        try {
            $response = Http::timeout((int) config($this->configKey().'.timeout', 20))
                ->get($this->statusUrl(), [
                    'merchantId' => $this->shopId(),
                    'orderCode' => $code,
                    'hash' => $this->hashFor($code),
                ]);
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('credo status unreachable', [
                'order' => $code,
                'error' => Redact::secrets($e->getMessage()),
            ]);

            return false;
        }

        $body = $response->json();

        if (($body['status'] ?? null) !== 200) {
            Log::channel('payments')->info('credo has no verdict yet', [
                'order' => $code,
                'status' => $body['status'] ?? $response->status(),
                'message' => $body['message'] ?? null,
            ]);

            return false;
        }

        return $this->readStatus($transaction, (int) ($body['data'] ?? 0), $body);
    }

    protected function readStatus(PaymentTransaction $transaction, int $status, array $body): bool
    {
        $transaction->update([
            'response' => array_merge((array) $transaction->response, ['status' => $body]),
            'checked_at' => now(),
        ]);

        if (in_array($status, [self::REJECTED, self::CANCELLED], true)) {
            /* Settled the other way: nothing is coming, so stop asking. */
            $transaction->update(['status' => PaymentTransaction::CANCELLED]);

            return false;
        }

        if ($status === self::SIGNED) {
            $this->noteSigned($transaction);
        }

        return $status === self::FINISHED;
    }

    /* Said once, not on every check. */
    protected function noteSigned(PaymentTransaction $transaction): void
    {
        $order = $transaction->order;

        if (! $order || $order->events()->where('type', 'payment')->where('note', __('order.credo_signed'))->exists()) {
            return;
        }

        $order->events()->create([
            'type' => 'payment',
            'note' => __('order.credo_signed'),
        ]);
    }

    public function handleCallback(array $payload): ?PaymentTransaction
    {
        $code = $payload['orderCode'] ?? $payload['order_code'] ?? null;

        if (! $code) {
            return null;
        }

        $transaction = PaymentTransaction::where('driver', $this->driverName())
            ->where('external_id', $code)
            ->first();

        $transaction?->update([
            'response' => array_merge((array) $transaction->response, ['callback' => $payload]),
        ]);

        return $transaction;
    }

    public function payload(Order $order): array
    {
        $fee = $this->fee();

        $products = $order->items->map(fn ($item) => [
            'id' => (string) $item->product_id,
            'title' => mb_substr($item->name, 0, 100),
            'amount' => (int) $item->qty,
            'price' => (int) round(((float) $item->price * (1 + $fee)) * 100),
            'type' => '0',
        ])->values()->all();

        return [
            'merchantId' => $this->shopId(),
            'orderCode' => (string) $order->number,
            'check' => $this->checksum($products),
            'products' => $products,
        ];
    }

    /*
     | The basket's signature, as Credo spells it out:
     |
     |   MD5(product[0].id + product[0].title + product[0].amount
     |       + product[0].price + product[0].type + … + product[n].type
     |       + secret)
     |
     | Every field of every line, in order, with the secret last. The shop
     | that has no secret of its own signs with the basket alone, which is
     | what it has always sent.
     */
    protected function checksum(array $products): string
    {
        $string = '';
        foreach ($products as $product) {
            $string .= $product['id'].$product['title'].$product['amount'].$product['price'].$product['type'].$this->secret();
        }
        Log::info($this->secret());
        Log::info($string);
        Log::info(md5($string));
        return md5($string);
    }

    public function total(Order $order): float
    {
        return round((float) $order->total * (1 + $this->fee()), 2);
    }
}
