<?php

namespace App\Services\Payments\Drivers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentDriver;

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

    public function confirm(PaymentTransaction $transaction): bool
    {
        return false;
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

    protected function checksum(array $products): string
    {
        $string = ' ';

        foreach ($products as $product) {
            $string .= $product['id'].$product['title'].$product['amount'].$product['price'].$product['type'];
        }

        return md5($string);
    }

    public function total(Order $order): float
    {
        return round((float) $order->total * (1 + $this->fee()), 2);
    }
}
