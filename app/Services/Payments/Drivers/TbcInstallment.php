<?php

namespace App\Services\Payments\Drivers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TbcInstallment implements PaymentDriver
{
    protected const STATUS_PENDING = 0;

    protected const STATUS_SUCCESS = 2;

    public function start(Order $order, array $options = []): PaymentTransaction
    {
        $fee = (float) config('tbc.installment.handling_fee', 0.05);

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

    public function confirm(PaymentTransaction $transaction): bool
    {
        if (! $transaction->external_id) {
            return false;
        }

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(20)

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
        return null;
    }

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
