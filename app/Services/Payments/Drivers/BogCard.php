<?php

namespace App\Services\Payments\Drivers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bank of Georgia card payments.
 *
 * Unlike instalments this is a plain redirect: the order is created, the
 * customer pays on the bank's page, and the bank posts back. The callback is
 * still only a nudge — the verdict is read from the bank's own receipt, so a
 * forged post cannot mark an order paid.
 */
class BogCard implements PaymentDriver
{
    public function start(Order $order, array $options = []): PaymentTransaction
    {
        $payload = $this->payload($order);

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            ->post(config('bog.payment.order_url'), $payload);

        $data = $response->json() ?? [];

        if (! $response->successful() || empty($data['id'])) {
            Log::channel('payments')->error('bog refused the payment', [
                'order' => $order->number,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException(__('checkout.payment_failed'));
        }

        return PaymentTransaction::create([
            'order_id' => $order->id,
            'driver' => 'bog-card',
            'external_id' => $data['id'],
            'amount' => $order->total,
            'status' => PaymentTransaction::PENDING,
            'request' => $payload,
            'response' => $data,
            'redirect_url' => $data['_links']['redirect']['href'] ?? null,
        ]);
    }

    /**
     * Reads the bank's receipt for an order.
     *
     * Only "completed" is money; "refunded" and "rejected" close the
     * transaction, and anything else leaves it pending for the next run.
     */
    public function confirm(PaymentTransaction $transaction): bool
    {
        if (! $transaction->external_id) {
            return false;
        }

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(20)
            ->get(rtrim((string) config('bog.payment.receipt_url'), '/').'/'.$transaction->external_id);

        if (! $response->successful()) {
            Log::channel('payments')->warning('bog receipt unavailable', [
                'transaction' => $transaction->id,
                'status' => $response->status(),
            ]);

            return false;
        }

        $data = $response->json() ?? [];
        $status = strtolower((string) data_get($data, 'order_status.key', ''));

        $transaction->update([
            'response' => array_merge((array) $transaction->response, ['receipt' => $data]),
            'checked_at' => now(),
        ]);

        // the bank echoes our own order number; a mismatch means a wrong receipt
        $echoed = (string) ($data['external_order_id'] ?? '');

        if ($echoed !== '' && $transaction->order && $echoed !== (string) $transaction->order->number) {
            Log::channel('payments')->error('bog returned a foreign receipt', [
                'transaction' => $transaction->id,
                'expected' => $transaction->order->number,
                'received' => $echoed,
            ]);

            return false;
        }

        if (in_array($status, ['rejected', 'refunded', 'cancelled'], true)) {
            $transaction->update(['status' => PaymentTransaction::FAILED]);

            return false;
        }

        return $status === 'completed';
    }

    /** The bank posts the whole order under a "body" key. */
    public function handleCallback(array $payload): ?PaymentTransaction
    {
        $externalId = data_get($payload, 'body.order_id') ?? data_get($payload, 'body.id') ?? $payload['order_id'] ?? null;

        if (! $externalId) {
            return null;
        }

        $transaction = PaymentTransaction::where('driver', 'bog-card')
            ->where('external_id', $externalId)
            ->first();

        $transaction?->update([
            'response' => array_merge((array) $transaction->response, ['callback' => $payload]),
        ]);

        return $transaction;
    }

    /* ------------------------------------------------------------------ plumbing */

    /** The bank's token lives five minutes, so a minute is kept back for the trip. */
    protected function token(): string
    {
        if ($cached = Cache::get('bog:payment:token')) {
            return $cached;
        }

        $response = Http::asForm()
            ->timeout(30)
            ->retry(2, 1000, throw: false)
            ->withHeaders([
                'Authorization' => 'Basic '.base64_encode(
                    config('bog.payment.public_key').':'.config('bog.payment.secret_key')
                ),
            ])
            ->post(config('bog.token_url'), ['grant_type' => 'client_credentials']);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('Could not authenticate with the bank.');
        }

        $token = (string) $response->json('access_token');
        $ttl = max(30, (int) $response->json('expires_in', 300) - 60);

        Cache::put('bog:payment:token', $token, now()->addSeconds($ttl));

        return $token;
    }

    /** @return array<string, mixed> */
    protected function payload(Order $order): array
    {
        $basket = $order->items->map(fn ($item) => [
            'product_id' => (string) $item->product_id,
            'quantity' => (int) $item->qty,
            'unit_price' => round((float) $item->price, 2),
        ])->values()->all();

        return [
            'callback_url' => config('bog.payment.callback_url')
                ?: route('payment.callback', ['driver' => 'bog-card']),
            // the order number, not the id: it is what the receipt echoes back
            'external_order_id' => (string) $order->number,
            'purchase_units' => [
                'currency' => 'GEL',
                'total_amount' => round((float) $order->total, 2),
                'basket' => $basket,
            ],
            'redirect_urls' => [
                'success' => route('payment.return', ['number' => $order->number, 'status' => 'success']),
                'fail' => route('payment.return', ['number' => $order->number, 'status' => 'fail']),
            ],
        ];
    }
}
