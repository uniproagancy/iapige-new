<?php

namespace App\Services\Payments\Drivers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentDriver;

/**
 * Credo instalments.
 *
 * Credo has no API: the shop posts a signed JSON blob to a widget from the
 * customer's own browser, and the bank takes over from there. So "starting" a
 * payment here means building that blob and sending the customer to a page
 * that submits it — the transaction records what we sent, not a conversation
 * we had.
 *
 * There is also nothing to ask afterwards. Credo confirms by telephone, so an
 * order is marked paid by hand in the admin.
 */
class CredoInstallment implements PaymentDriver
{
    /** What the shop adds to cover Credo's own cost. */
    protected const FEE = 0.10;

    public function start(Order $order, array $options = []): PaymentTransaction
    {
        $payload = $this->payload($order);

        return PaymentTransaction::create([
            'order_id'    => $order->id,
            'driver'      => 'credo-installment',
            // Credo identifies an application by our own order code
            'external_id' => (string) $order->number,
            'amount'      => $this->total($order),
            'status'      => PaymentTransaction::PENDING,
            'request'     => $payload,
            // the widget needs a form post, so the customer goes to our own page
            'redirect_url' => route('payment.credo.form', $order->number),
        ]);
    }

    /**
     * Credo tells us nothing, so nothing can be confirmed automatically.
     *
     * Returning false leaves the transaction pending, which is honest: the
     * order waits for somebody to mark it paid once Credo calls.
     */
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

        $transaction = PaymentTransaction::where('driver', 'credo-installment')
            ->where('external_id', $code)
            ->first();

        $transaction?->update([
            'response' => array_merge((array) $transaction->response, ['callback' => $payload]),
        ]);

        return $transaction;
    }

    /* ------------------------------------------------------------------ payload */

    /**
     * The widget's payload, signed.
     *
     * Prices travel in tetri and the checksum is built from the product rows
     * in the order they are sent, so the two must never be built separately.
     *
     * @return array<string, mixed>
     */
    public function payload(Order $order): array
    {
        $products = $order->items->map(fn ($item) => [
            'id'     => (string) $item->product_id,
            'title'  => mb_substr($item->name, 0, 100),
            'amount' => (int) $item->qty,
            'price'  => (int) round(((float) $item->price * (1 + self::FEE)) * 100),
            'type'   => '0',
        ])->values()->all();

        return [
            'merchantId' => (string) config('credo.merchant_id'),
            'orderCode'  => (string) $order->number,
            'check'      => $this->checksum($products),
            'products'   => $products,
        ];
    }

    /** @param array<int, array> $products */
    protected function checksum(array $products): string
    {
        // the leading space is part of Credo's own scheme, not an accident
        $string = ' ';

        foreach ($products as $product) {
            $string .= $product['id'].$product['title'].$product['amount'].$product['price'].$product['type'];
        }

        return md5($string);
    }

    /** What the customer will owe Credo, fee included. */
    public function total(Order $order): float
    {
        return round((float) $order->total * (1 + self::FEE), 2);
    }
}
