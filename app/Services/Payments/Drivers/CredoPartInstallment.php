<?php

namespace App\Services\Payments\Drivers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentDriver;

use Log;

class CredoPartInstallment implements PaymentDriver
{
    protected const FEE = 0.10;

    public function start(Order $order, array $options = []): PaymentTransaction
    {
        $payload = $this->payload($order);

        return PaymentTransaction::create([
            'order_id' => $order->id,
            'driver' => 'credo-part-installment',
            'external_id' => (string) $order->number,
            'amount' => $this->total($order),
            'status' => PaymentTransaction::PENDING,
            'request' => $payload,
            'redirect_url' => route('payment.credo_part.form', $order->number),
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

        $transaction = PaymentTransaction::where('driver', 'credo-part-installment')
            ->where('external_id', $code)
            ->first();

        $transaction?->update([
            'response' => array_merge((array) $transaction->response, ['callback' => $payload]),
        ]);

        return $transaction;
    }

    public function payload(Order $order): array
    {
        $products = $order->items->map(fn ($item) => [
            'id' => (string) $item->product_id,
            'title' => mb_substr($item->name, 0, 100),
            'amount' => (int) $item->qty,
            'price' => (int) round(((float) $item->price * (1 + self::FEE)) * 100),
            'type' => '0',
        ])->values()->all();

        return [
            'merchantId' => (string) config('credo_part.merchant_id'),
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
		Log::warning(md5($string));
        return md5($string);
    }

    public function total(Order $order): float
    {
        return round((float) $order->total * (1 + self::FEE), 2);
    }
}
