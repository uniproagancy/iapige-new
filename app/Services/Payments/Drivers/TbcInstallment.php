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
 * TBC online instalments, spoken to directly.
 *
 * The published package stopped at PHP 7, and the protocol is three requests:
 * a token, an application, and a status. Writing them out is less code than
 * carrying a dependency that pins Guzzle a major version back.
 *
 * TBC sends no callbacks, so payments:check is what closes these orders.
 */
class TbcInstallment implements PaymentDriver
{
    /** What the bank's status endpoint means by its numbers. */
    protected const STATUS_PENDING = 0;

    protected const STATUS_SUCCESS = 2;

    public function start(Order $order, array $options = []): PaymentTransaction
    {
        $fee = (float) config('tbc.installment.handling_fee', 0.05);

        /*
         * The bank checks that the product rows add up to priceTotal, so both
         * are built from the same rounded figures — rounding each line and the
         * total separately is what makes an application bounce.
         */
        $products = $order->items->map(fn ($item) => [
            'name' => mb_substr($item->name, 0, 100),
            'price' => round((float) $item->price * (1 + $fee), 2),
            'quantity' => (int) $item->qty,
        ])->values()->all();

        $total = round(array_sum(array_map(
            fn ($product) => $product['price'] * $product['quantity'],
            $products,
        )), 2);

        $payload = [
            'merchantKey' => (string) config('tbc.installment.merchant_key'),
            'priceTotal' => $total,
            'campaignId' => (string) config('tbc.installment.campaign_id'),
            'invoiceId' => (string) $order->number,
            'products' => $products,
        ];

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            ->post($this->url('/v1/online-installments/applications'), $payload);

        $data = $response->json() ?? [];

        // the redirect is a header, not a field, which is easy to miss
        $redirect = $response->header('Location');

        if (! $response->successful() || empty($data['sessionId']) || ! $redirect) {
            Log::channel('payments')->error('tbc refused the application', [
                'order' => $order->number,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException(__('checkout.payment_failed'));
        }

        return PaymentTransaction::create([
            'order_id' => $order->id,
            'driver' => 'tbc-installment',
            'external_id' => $data['sessionId'],
            'amount' => $total,
            'status' => PaymentTransaction::PENDING,
            'request' => $payload,
            'response' => $data,
            'redirect_url' => $redirect,
        ]);
    }

    /**
     * Asks the bank what became of an application.
     *
     * Status 0 means the customer has not finished, which is not a failure: it
     * stays pending so the next run asks again. Anything else that is not
     * success is a refusal and closes the transaction.
     */
    public function confirm(PaymentTransaction $transaction): bool
    {
        if (! $transaction->external_id) {
            return false;
        }

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(20)
            // the bank wants its key in the body of a GET, which is unusual but required
            ->withBody(
                json_encode(['merchantKey' => (string) config('tbc.installment.merchant_key')]),
                'application/json',
            )
            ->get($this->url("/v1/online-installments/applications/{$transaction->external_id}/status"));

        if (! $response->successful()) {
            Log::channel('payments')->warning('tbc status unavailable', [
                'transaction' => $transaction->id,
                'status' => $response->status(),
            ]);

            return false;
        }

        $data = $response->json() ?? [];
        $status = $data['statusId'] ?? null;

        $transaction->update([
            'response' => array_merge((array) $transaction->response, ['last_check' => $data]),
            'checked_at' => now(),
        ]);

        if ($status === self::STATUS_SUCCESS) {
            return true;
        }

        if ($status !== null && $status !== self::STATUS_PENDING) {
            $transaction->update(['status' => PaymentTransaction::FAILED]);
        }

        return false;
    }

    public function handleCallback(array $payload): ?PaymentTransaction
    {
        return null;   // TBC does not send any
    }

    /**
     * Cancels an application the shop no longer wants to honour.
     *
     * Not part of the interface, but the admin needs it when an order is
     * cancelled before the bank has disbursed.
     */
    public function cancel(PaymentTransaction $transaction): bool
    {
        if (! $transaction->external_id) {
            return false;
        }

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->asForm()
            ->timeout(20)
            ->post(
                $this->url("/v1/online-installments/applications/{$transaction->external_id}/cancel"),
                ['merchantKey' => (string) config('tbc.installment.merchant_key')],
            );

        if ($response->successful()) {
            $transaction->update(['status' => PaymentTransaction::CANCELLED]);
        }

        return $response->successful();
    }

    /* ------------------------------------------------------------------ plumbing */

    protected function token(): string
    {
        if ($cached = Cache::get('tbc:installment:token')) {
            return $cached;
        }

        $response = Http::acceptJson()
            ->asForm()
            ->timeout(30)
            ->retry(2, 1000, throw: false)
            ->post($this->url('/oauth/token'), [
                'client_id' => config('tbc.installment.client_id'),
                'client_secret' => config('tbc.installment.client_secret'),
                'merchant-key' => config('tbc.installment.merchant_key'),
                'grant_type' => 'client_credentials',
                'scope' => 'online_installments',
            ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            Log::channel('payments')->error('tbc authentication failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Could not authenticate with TBC.');
        }

        $token = (string) $response->json('access_token');
        $ttl = max(30, (int) $response->json('expires_in', 3600) - 60);

        Cache::put('tbc:installment:token', $token, now()->addSeconds($ttl));

        return $token;
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('tbc.base_url'), '/').$path;
    }
}
